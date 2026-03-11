<?php

namespace Modules\MailboxAutoDistributor\Services;

use App\Conversation;
use Illuminate\Support\Facades\DB;
use Modules\MailboxAutoDistributor\Models\PendingAssignment;

class PendingProcessor
{
    /** @var Assigner */
    protected $assigner;

    public function __construct(Assigner $assigner)
    {
        $this->assigner = $assigner;
    }

    /**
     * Process due pending assignments. Returns number transitioned to terminal states.
     */
    public function processDue(int $limit = 50): int
    {
        $limit = max(1, min(500, $limit));

        $pendingIds = PendingAssignment::query()
            ->where('status', 'pending')
            ->where('run_at', '<=', now())
            ->orderBy('run_at', 'asc')
            ->limit($limit)
            ->toBase()
            ->pluck('id')
            ->all();

        if (!$pendingIds) {
            return 0;
        }

        $processed = 0;

        foreach ($pendingIds as $pendingId) {
            $pendingId = (int)$pendingId;

            try {
                DB::transaction(function () use ($pendingId, &$processed) {
                    $locked = PendingAssignment::where('id', $pendingId)->lockForUpdate()->first();
                    if (!$locked || $locked->status !== 'pending') {
                        return;
                    }

                    $conversation = Conversation::where('id', $locked->conversation_id)->lockForUpdate()->first();
                    if (!$conversation) {
                        $this->markTerminal($locked, 'failed', 'Conversation not found');
                        $processed++;
                        return;
                    }

                    if ((int)$conversation->mailbox_id !== (int)$locked->mailbox_id) {
                        $this->markTerminal($locked, 'failed', 'Mailbox mismatch');
                        $processed++;
                        return;
                    }

                    if (!empty($conversation->user_id)) {
                        $this->markTerminal($locked, 'skipped', 'Already assigned');
                        $processed++;
                        return;
                    }

                    // Force immediate assignment (bypass defer).
                    $this->assigner->assignNow($conversation, [
                        'source' => 'deferred',
                        'snapshot' => $this->sanitizeSnapshot($locked->snapshot),
                    ]);

                    // After assignNow(), conversation may still be unassigned (pool empty, excluded, etc.)
                    if (!empty($conversation->fresh()->user_id)) {
                        $this->markTerminal($locked, 'assigned', null);
                    } else {
                        $this->markTerminal($locked, 'skipped', 'No eligible assignee');
                    }
                    $processed++;
                }, 3);
            } catch (\Throwable $e) {
                // Do not expose exception internals in persistent reason fields.
                $updated = PendingAssignment::where('id', $pendingId)
                    ->where('status', 'pending')
                    ->update([
                        'status' => 'failed',
                        'reason' => 'Processing error',
                        'processed_at' => now(),
                    ]);

                if ($updated > 0) {
                    $processed++;
                }
            }
        }

        return $processed;
    }


    protected function sanitizeSnapshot($snapshot): array
    {
        if (!is_array($snapshot)) {
            return [];
        }

        return [
            'mode' => isset($snapshot['mode']) ? (string)$snapshot['mode'] : null,
            'users' => isset($snapshot['users']) && is_array($snapshot['users'])
                ? array_values(array_filter(array_map('intval', $snapshot['users']), function ($id) { return $id > 0; }))
                : [],
        ];
    }

    protected function markTerminal(PendingAssignment $assignment, string $status, ?string $reason): void
    {
        $allowedStatuses = ['assigned', 'skipped', 'failed'];
        if (!in_array($status, $allowedStatuses, true)) {
            $status = 'failed';
            $reason = 'Invalid terminal status';
        }

        $assignment->status = $status;
        $assignment->reason = $reason;
        $assignment->processed_at = now();
        $assignment->save();
    }
}
