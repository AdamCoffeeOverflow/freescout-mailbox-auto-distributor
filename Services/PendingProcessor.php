<?php

namespace Modules\MailboxAutoDistributor\Services;

use App\Conversation;
use Illuminate\Support\Facades\DB;
use Modules\MailboxAutoDistributor\Models\PendingAssignment;

class PendingProcessor
{
    protected Assigner $assigner;

    public function __construct(Assigner $assigner)
    {
        $this->assigner = $assigner;
    }

    /**
     * Process due pending assignments. Returns number processed (assigned or skipped/failed).
     */
    public function processDue(int $limit = 50): int
    {
        $limit = max(1, min(500, $limit));

        $now = now();

        $items = PendingAssignment::where('status', 'pending')
            ->where('run_at', '<=', $now)
            ->orderBy('run_at', 'asc')
            ->limit($limit)
            ->get();

        $processed = 0;

        foreach ($items as $item) {
            $processed++;

            DB::transaction(function () use ($item) {
                $locked = PendingAssignment::where('id', $item->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'pending') {
                    return;
                }

                $conversation = Conversation::find($locked->conversation_id);
                if (!$conversation) {
                    $locked->status = 'failed';
                    $locked->reason = 'Conversation not found';
                    $locked->processed_at = now();
                    $locked->save();
                    return;
                }

                if (!empty($conversation->user_id)) {
                    $locked->status = 'skipped';
                    $locked->reason = 'Already assigned';
                    $locked->processed_at = now();
                    $locked->save();
                    return;
                }

                // Force immediate assignment (bypass defer).
                $this->assigner->assignNow($conversation, [
                    'source' => 'deferred',
                    'snapshot' => $locked->snapshot,
                ]);

                // After assignNow(), conversation may still be unassigned (pool empty, excluded, etc.)
                if (!empty($conversation->fresh()->user_id)) {
                    $locked->status = 'assigned';
                    $locked->reason = null;
                } else {
                    $locked->status = 'skipped';
                    $locked->reason = 'No eligible assignee';
                }
                $locked->processed_at = now();
                $locked->save();
            }, 3);
        }

        return $processed;
    }
}
