<?php

namespace Modules\MailboxAutoDistributor\Services;

use App\Mailbox;

class Settings
{
    public const ALIAS = 'mailboxautodistributor';

    /**
     * Get normalized settings for a mailbox.
     */
    public function forMailbox(Mailbox $mailbox): array
    {
        $defaults = config(self::ALIAS.'.defaults', []);
        if (!is_array($defaults)) {
            $defaults = [];
        }

        $meta = $mailbox->meta[self::ALIAS] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $s = array_merge($defaults, $meta);

        // Normalize.
        $s['enabled'] = !empty($s['enabled']) ? 1 : 0;
        $s['mode'] = in_array(($s['mode'] ?? 'round_robin'), ['round_robin', 'least_open'], true)
            ? $s['mode']
            : 'round_robin';

        $users = $s['users'] ?? [];
        if (!is_array($users)) {
            $users = [];
        }
        $users = array_values(array_unique(array_map('intval', $users)));
        $users = array_values(array_filter($users, fn ($id) => $id > 0));
        $s['users'] = $users;

        $s['defer_enabled'] = !empty($s['defer_enabled']) ? 1 : 0;
        $s['defer_minutes'] = max(1, min(60, (int)($s['defer_minutes'] ?? 5)));
        $s['web_fallback'] = !empty($s['web_fallback']) ? 1 : 0;

        $s['sticky_enabled'] = !empty($s['sticky_enabled']) ? 1 : 0;
        $s['sticky_days'] = max(1, min(365, (int)($s['sticky_days'] ?? 60)));

        $s['exclude_tags'] = trim((string)($s['exclude_tags'] ?? ''));

        $s['fallback_user_id'] = max(0, (int)($s['fallback_user_id'] ?? 0));

        $s['override_default_assignee'] = !empty($s['override_default_assignee']) ? 1 : 0;

        $s['audit_enabled'] = !empty($s['audit_enabled']) ? 1 : 0;

        // Pointer.
        $s['last_assigned_user_id'] = max(0, (int)($s['last_assigned_user_id'] ?? 0));

        return $s;
    }
}
