<?php

namespace Modules\MailboxAutoDistributor\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'mailbox_auto_distributor_audit';

    protected $fillable = [
        'mailbox_id',
        'conversation_id',
        'assigned_user_id',
        'action',
        'mode',
        'reason',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
    ];
}
