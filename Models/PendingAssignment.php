<?php

namespace Modules\MailboxAutoDistributor\Models;

use Illuminate\Database\Eloquent\Model;

class PendingAssignment extends Model
{
    protected $table = 'mailbox_auto_distributor_pending';

    protected $fillable = [
        'mailbox_id',
        'conversation_id',
        'run_at',
        'status',
        'processed_at',
        'reason',
        'snapshot',
    ];

    protected $casts = [
        'run_at' => 'datetime',
        'processed_at' => 'datetime',
        'snapshot' => 'array',
    ];
}
