<?php

namespace Modules\MailboxAutoDistributor\Services;

use App\Conversation;
use App\Folder;
use App\Mailbox;
use App\User;
use Illuminate\Support\Facades\DB;

class Assigner
{
    /**
     * Assigns conversation if mailbox auto-distribution is enabled and conversation is unassigned.
     */
    public function assignIfEnabled(Conversation $conversation): void
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
        DB::transaction(function () use ($mailboxId, $conversation) {
            /** @var Mailbox|null $mailbox */
            $mailbox = Mailbox::where('id', $mailboxId)->lockForUpdate()->first();
            if (!$mailbox) {
                return;
            }

            $meta = $mailbox->meta[MAILBOXAUTODISTRIBUTOR_MODULE] ?? [];
            if (!is_array($meta) || empty($meta['enabled'])) {
                return;
            }

            $mode = $meta['mode'] ?? 'round_robin';
            $eligible = $meta['users'] ?? [];
            if (!is_array($eligible) || !count($eligible)) {
                return;
            }

            // Ensure users are valid, active, and have mailbox access.
            $eligible = array_values(array_unique(array_map('intval', $eligible)));
            $eligible = array_filter($eligible, fn($id) => $id > 0);
            if (!$eligible) {
                return;
            }

            $mailboxUserIds = $mailbox->users()->pluck('users.id')->toArray();
            $eligible = array_values(array_intersect($eligible, $mailboxUserIds));
            if (!$eligible) {
                return;
            }

            $activeUsers = User::whereIn('id', $eligible)->get()->filter(fn($u) => $u->isActive())->pluck('id')->toArray();
            $eligible = array_values(array_intersect($eligible, $activeUsers));
            if (!$eligible) {
                return;
            }

            $lastAssigned = (int)($meta['last_assigned_user_id'] ?? 0);

            $selectedUserId = null;
            if ($mode === 'least_open') {
                $selectedUserId = $this->pickLeastOpen($mailboxId, $eligible, $lastAssigned);
            } else {
                $selectedUserId = $this->pickRoundRobin($eligible, $lastAssigned);
                $mode = 'round_robin';
            }

            if (!$selectedUserId) {
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
            $meta['mode'] = $mode;
            $mailbox->setMetaParam(MAILBOXAUTODISTRIBUTOR_MODULE, $meta);
            $mailbox->save();
        }, 3);
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
     * Least-open selection with round-robin tie-break.
     */
    protected function pickLeastOpen(int $mailboxId, array $eligible, int $lastAssigned): ?int
    {
        sort($eligible);
        if (!$eligible) {
            return null;
        }

        $counts = Conversation::where('mailbox_id', $mailboxId)
            ->whereIn('status', [Conversation::STATUS_ACTIVE, Conversation::STATUS_PENDING])
            ->whereIn('user_id', $eligible)
            ->select('user_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('user_id')
            ->pluck('cnt', 'user_id')
            ->toArray();

        $min = null;
        $candidates = [];
        foreach ($eligible as $uid) {
            $cnt = (int)($counts[$uid] ?? 0);
            if ($min === null || $cnt < $min) {
                $min = $cnt;
                $candidates = [(int)$uid];
            } elseif ($cnt === $min) {
                $candidates[] = (int)$uid;
            }
        }

        if (!$candidates) {
            return null;
        }

        // If multiple candidates, use round-robin among candidates.
        return $this->pickRoundRobin($candidates, $lastAssigned);
    }
}
