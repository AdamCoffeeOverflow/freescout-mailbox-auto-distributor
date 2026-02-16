<?php

namespace Modules\MailboxAutoDistributor\Services;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\User;
use Illuminate\Support\Facades\DB;
use Modules\MailboxAutoDistributor\Models\AuditLog;
use Modules\MailboxAutoDistributor\Models\PendingAssignment;

class Assigner
{
    public function __construct()
    {
        // Make the module alias constant available even if service is resolved
        // in unusual boot orders (e.g. artisan contexts).
        if (!defined('MAILBOXAUTODISTRIBUTOR_MODULE')) {
            define('MAILBOXAUTODISTRIBUTOR_MODULE', 'mailboxautodistributor');
        }
    }

    /**
     * Entry point from events: respects "defer/workflows-first" mode.
     */
    public function assignIfEnabled(Conversation $conversation): void
    {
        $this->assignInternal($conversation, false, ['source' => 'event']);
    }

    /**
     * Used by deferred processor: bypasses "defer/workflows-first" and attempts assignment immediately.
     *
     * @param array $context Extra meta for audit/debugging.
     */
    public function assignNow(Conversation $conversation, array $context = []): void
    {
        $this->assignInternal($conversation, true, $context + ['source' => 'manual']);
    }

    protected function assignInternal(Conversation $conversation, bool $ignoreDefer, array $context = []): void
    {
        // Only assign if unassigned.
        if (!empty($conversation->user_id)) {
            return;
        }

        $mailboxId = (int)$conversation->mailbox_id;
        if (!$mailboxId) {
            return;
        }

        // Transaction protects round-robin pointer from race conditions.
        DB::transaction(function () use ($mailboxId, $conversation, $ignoreDefer, $context) {
            /** @var Mailbox|null $mailbox */
            $mailbox = Mailbox::where('id', $mailboxId)->lockForUpdate()->first();
            if (!$mailbox) {
                return;
            }

            $meta = $mailbox->meta[MAILBOXAUTODISTRIBUTOR_MODULE] ?? [];
            if (!is_array($meta) || empty($meta['enabled'])) {
                return;
            }

            // Exclusions: skip if any excluded tag is present.
            if ($this->isExcludedByTags($conversation, $meta)) {
                $this->audit($meta, $mailboxId, (int)$conversation->id, null, 'skipped', null, 'Excluded by tag', [
                    'source' => $context['source'] ?? 'event',
                ]);
                return;
            }

            // Defer mode (Workflows-first): enqueue and return.
            if (!$ignoreDefer && !empty($meta['defer_enabled'])) {
                $minutes = (int)($meta['defer_minutes'] ?? 5);
                $minutes = max(1, min(60, $minutes));

                $this->enqueueDeferred($meta, $mailboxId, (int)$conversation->id, $minutes);

                $this->audit($meta, $mailboxId, (int)$conversation->id, null, 'enqueued', 'deferred', null, [
                    'run_in_minutes' => $minutes,
                    'source' => $context['source'] ?? 'event',
                ]);
                return;
            }

            $eligible = $meta['users'] ?? [];
            if (!is_array($eligible) || !count($eligible)) {
                // Fallback (optional)
                $this->tryFallback($mailbox, $meta, $conversation, $context, 'Pool empty');
                return;
            }

            // Ensure users are valid, active, and have mailbox access.
            $eligible = array_values(array_unique(array_map('intval', $eligible)));
            $eligible = array_filter($eligible, fn($id) => $id > 0);
            if (!$eligible) {
                $this->tryFallback($mailbox, $meta, $conversation, $context, 'No valid users in pool');
                return;
            }

            $mailboxUserIds = $mailbox->users()->pluck('users.id')->toArray();
            $eligible = array_values(array_intersect($eligible, $mailboxUserIds));
            if (!$eligible) {
                $this->tryFallback($mailbox, $meta, $conversation, $context, 'Pool users have no mailbox access');
                return;
            }

            $activeUsers = User::whereIn('id', $eligible)->get()->filter(fn($u) => $u->isActive())->pluck('id')->toArray();
            $eligible = array_values(array_intersect($eligible, $activeUsers));
            if (!$eligible) {
                $this->tryFallback($mailbox, $meta, $conversation, $context, 'Pool users inactive');
                return;
            }

            $lastAssigned = (int)($meta['last_assigned_user_id'] ?? 0);

            // Sticky assignment (same customer + normalized subject)
            $stickyUserId = null;
            if (!empty($meta['sticky_enabled'])) {
                $days = (int)($meta['sticky_days'] ?? 60);
                $days = max(1, min(365, $days));
                $stickyUserId = $this->findStickyAssignee($conversation, $mailboxId, $days, $eligible);
            }

            $mode = $meta['mode'] ?? 'round_robin';
            $selectedUserId = null;

            if ($stickyUserId) {
                $selectedUserId = $stickyUserId;
                $mode = 'sticky';
            } elseif ($mode === 'least_open') {
                $selectedUserId = $this->pickLeastOpen($mailboxId, $eligible, $lastAssigned);
                $mode = 'least_open';
            } else {
                $selectedUserId = $this->pickRoundRobin($eligible, $lastAssigned);
                $mode = 'round_robin';
            }

            if (!$selectedUserId) {
                $this->tryFallback($mailbox, $meta, $conversation, $context, 'No eligible assignee selected');
                return;
            }

            // Assign.
            $conversationFresh = Conversation::where('id', $conversation->id)->lockForUpdate()->first();
            if (!$conversationFresh || !empty($conversationFresh->user_id)) {
                return;
            }

            // Assign using FreeScout helper so folder_id is updated correctly.
            $oldFolderId = (int)$conversationFresh->folder_id;
            $conversationFresh->setUser((int)$selectedUserId);
            $conversationFresh->save();

            // Update folder counters so sidebar badge reflects the move immediately.
            $newFolderId = (int)$conversationFresh->folder_id;
            if ($oldFolderId && $oldFolderId !== $newFolderId) {
                $oldFolder = Folder::find($oldFolderId);
                if ($oldFolder) {
                    $oldFolder->updateCounters();
                }
            }
            if ($newFolderId) {
                $newFolder = Folder::find($newFolderId);
                if ($newFolder) {
                    $newFolder->updateCounters();
                }
            }

            // Persist pointer.
            $meta['last_assigned_user_id'] = (int)$selectedUserId;
            $meta['mode'] = ($meta['mode'] ?? 'round_robin'); // keep configured mode
            $mailbox->setMetaParam(MAILBOXAUTODISTRIBUTOR_MODULE, $meta);
            $mailbox->save();

            $this->audit($meta, $mailboxId, (int)$conversation->id, (int)$selectedUserId, 'assigned', $mode, null, [
                'source' => $context['source'] ?? 'event',
            ]);
        }, 3);
    }

    protected function enqueueDeferred(array $meta, int $mailboxId, int $conversationId, int $minutes): void
    {
        // Upsert by conversation_id to avoid duplicates.
        PendingAssignment::updateOrCreate(
            ['conversation_id' => $conversationId],
            [
                'mailbox_id' => $mailboxId,
                'run_at' => now()->addMinutes($minutes),
                'status' => 'pending',
                'processed_at' => null,
                'reason' => null,
                'snapshot' => [
                    'mode' => $meta['mode'] ?? 'round_robin',
                    'users' => $meta['users'] ?? [],
                ],
            ]
        );
    }

    protected function tryFallback(Mailbox $mailbox, array $meta, Conversation $conversation, array $context, string $reason): void
    {
        $fallbackId = (int)($meta['fallback_user_id'] ?? 0);
        if (!$fallbackId) {
            $this->audit($meta, (int)$mailbox->id, (int)$conversation->id, null, 'skipped', null, $reason, [
                'source' => $context['source'] ?? 'event',
            ]);
            return;
        }

        // Validate fallback is active and has mailbox access.
        $mailboxUserIds = $mailbox->users()->pluck('users.id')->toArray();
        if (!in_array($fallbackId, $mailboxUserIds, true)) {
            $this->audit($meta, (int)$mailbox->id, (int)$conversation->id, null, 'skipped', null, 'Fallback has no mailbox access', [
                'source' => $context['source'] ?? 'event',
            ]);
            return;
        }

        $user = User::find($fallbackId);
        if (!$user || !$user->isActive()) {
            $this->audit($meta, (int)$mailbox->id, (int)$conversation->id, null, 'skipped', null, 'Fallback inactive', [
                'source' => $context['source'] ?? 'event',
            ]);
            return;
        }

        $conversationFresh = Conversation::where('id', $conversation->id)->lockForUpdate()->first();
        if (!$conversationFresh || !empty($conversationFresh->user_id)) {
            return;
        }

        $oldFolderId = (int)$conversationFresh->folder_id;
        $conversationFresh->setUser($fallbackId);
        $conversationFresh->save();

        $newFolderId = (int)$conversationFresh->folder_id;
        if ($oldFolderId && $oldFolderId !== $newFolderId) {
            $oldFolder = Folder::find($oldFolderId);
            if ($oldFolder) {
                $oldFolder->updateCounters();
            }
        }
        if ($newFolderId) {
            $newFolder = Folder::find($newFolderId);
            if ($newFolder) {
                $newFolder->updateCounters();
            }
        }

        $this->audit($meta, (int)$mailbox->id, (int)$conversation->id, $fallbackId, 'assigned', 'fallback', $reason, [
            'source' => $context['source'] ?? 'event',
        ]);
    }

    protected function isExcludedByTags(Conversation $conversation, array $meta): bool
    {
        $exclude = $meta['exclude_tags'] ?? '';
        if (!is_string($exclude) || trim($exclude) === '') {
            return false;
        }

        $excludeList = array_filter(array_map(fn($t) => mb_strtolower(trim($t)), preg_split('/[,\n]+/', $exclude)));
        if (!$excludeList) {
            return false;
        }

        $tags = [];

        // Try common ways FreeScout exposes tags.
        if (method_exists($conversation, 'tags')) {
            try {
                $tags = $conversation->tags()->pluck('name')->toArray();
            } catch (\Throwable $e) {
                // ignore
            }
        }
        if (!$tags && property_exists($conversation, 'tags') && is_array($conversation->tags)) {
            $tags = $conversation->tags;
        }
        if (!$tags && isset($conversation->meta['tags']) && is_array($conversation->meta['tags'])) {
            $tags = $conversation->meta['tags'];
        }

        $tags = array_map(fn($t) => mb_strtolower(trim((string)$t)), $tags);
        foreach ($tags as $tag) {
            if ($tag !== '' && in_array($tag, $excludeList, true)) {
                return true;
            }
        }
        return false;
    }

    protected function findStickyAssignee(Conversation $conversation, int $mailboxId, int $days, array $eligible): ?int
    {
        $customerId = (int)($conversation->customer_id ?? 0);
        if (!$customerId) {
            return null;
        }

        $subject = $this->normalizeSubject((string)($conversation->subject ?? ''));
        if ($subject === '') {
            return null;
        }

        $since = now()->subDays($days);

        $candidates = Conversation::where('mailbox_id', $mailboxId)
            ->where('customer_id', $customerId)
            ->where('id', '!=', $conversation->id)
            ->whereNotNull('user_id')
            ->where('created_at', '>=', $since)
            ->orderBy('created_at', 'desc')
            ->limit(25)
            ->get(['id', 'subject', 'user_id']);

        foreach ($candidates as $c) {
            $s = $this->normalizeSubject((string)$c->subject);
            if ($s !== '' && $s === $subject) {
                $uid = (int)$c->user_id;
                if ($uid && in_array($uid, $eligible, true)) {
                    return $uid;
                }
            }
        }

        return null;
    }

    protected function normalizeSubject(string $subject): string
    {
        $s = trim(mb_strtolower($subject));
        if ($s === '') {
            return '';
        }

        // Strip common reply/forward prefixes repeatedly.
        $prefixes = [
            're', 'fw', 'fwd', 'aw', 'sv', 'tr', 'wg', 'antwoord', 'antwort', 'réf', 'vs', 'enc',
        ];

        $pattern = '/^(' . implode('|', array_map('preg_quote', $prefixes)) . ')\s*:\s*/iu';
        for ($i = 0; $i < 5; $i++) {
            $new = preg_replace($pattern, '', $s);
            if ($new === $s) {
                break;
            }
            $s = trim($new);
        }

        // Collapse whitespace.
        $s = preg_replace('/\s+/u', ' ', $s);
        return trim($s);
    }

    protected function audit(array $meta, int $mailboxId, int $conversationId, ?int $assignedUserId, string $action, ?string $mode, ?string $reason, array $extraMeta = []): void
    {
        if (empty($meta['audit_enabled'])) {
            return;
        }

        try {
            AuditLog::create([
                'mailbox_id' => $mailboxId,
                'conversation_id' => $conversationId,
                'assigned_user_id' => $assignedUserId,
                'action' => $action,
                'mode' => $mode,
                'reason' => $reason,
                'meta' => $extraMeta,
            ]);

            // Prune to last 200 per mailbox to avoid growth.
            $keep = 200;
            $ids = AuditLog::where('mailbox_id', $mailboxId)
                ->orderBy('id', 'desc')
                ->skip($keep)
                ->take(500)
                ->pluck('id')
                ->toArray();
            if ($ids) {
                AuditLog::whereIn('id', $ids)->delete();
            }
        } catch (\Throwable $e) {
            // Never break ticket creation due to logging.
        }
    }

    /**
     * Round-robin selection from ordered list.
     */
    protected function pickRoundRobin(array $eligible, int $lastAssigned): ?int
    {
        sort($eligible);

        if (!$eligible) {
            return null;
        }

        if (!$lastAssigned || !in_array($lastAssigned, $eligible, true)) {
            return (int)$eligible[0];
        }

        $idx = array_search($lastAssigned, $eligible, true);
        if ($idx === false) {
            return (int)$eligible[0];
        }

        $nextIdx = $idx + 1;
        if ($nextIdx >= count($eligible)) {
            $nextIdx = 0;
        }

        return (int)$eligible[$nextIdx];
    }

    /**
     * Pick user with least open conversations (Active + Pending) in a mailbox.
     * Tie-breaks using round-robin next selection.
     */
    protected function pickLeastOpen(int $mailboxId, array $eligible, int $lastAssigned): ?int
    {
        sort($eligible);

        if (!$eligible) {
            return null;
        }

        // Count open conversations per user in this mailbox.
        // Statuses: Active (1) + Pending (2) are typical "open". This is conservative and compatible.
        $counts = Conversation::selectRaw('user_id, COUNT(*) as c')
            ->where('mailbox_id', $mailboxId)
            ->whereIn('user_id', $eligible)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->groupBy('user_id')
            ->pluck('c', 'user_id')
            ->toArray();

        $min = null;
        $best = [];

        foreach ($eligible as $uid) {
            $c = (int)($counts[$uid] ?? 0);
            if ($min === null || $c < $min) {
                $min = $c;
                $best = [$uid];
            } elseif ($c === $min) {
                $best[] = $uid;
            }
        }

        if (!$best) {
            return null;
        }

        // If tie, use round-robin within tied set.
        return $this->pickRoundRobin($best, $lastAssigned);
    }
}
