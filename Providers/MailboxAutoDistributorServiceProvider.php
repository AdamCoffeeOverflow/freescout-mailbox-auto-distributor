<?php

namespace Modules\MailboxAutoDistributor\Providers;

use Illuminate\Support\ServiceProvider;

if (!defined('MAILBOXAUTODISTRIBUTOR_MODULE')) {
    define('MAILBOXAUTODISTRIBUTOR_MODULE', 'mailboxautodistributor');
}

class MailboxAutoDistributorServiceProvider extends ServiceProvider
{
    /**
     * Boot the application events.
     */
    public function boot()
    {
        $this->registerTranslations();
        $this->registerViews();

        // Migrations.
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        // Page-specific JS (CSP-safe external script).
        \Eventy::addFilter('javascripts', function ($javascripts) {
            try {
                // Mailbox settings screen (GET edit + POST update). FreeScout route names may vary by version,
                // so we use path matching for maximum compatibility.
                if (\Request::is('mailboxes/*')) {
                    $javascripts[] = \Module::getPublicPath(MAILBOXAUTODISTRIBUTOR_MODULE) . '/js/mad.js';
                }
            } catch (\Throwable $e) {
                // Ignore.
            }
            return $javascripts;
        }, 20, 1);

        // Mailbox settings UI.
        \Eventy::addAction('mailbox.update.after_signature', [$this, 'mailboxSettingsSection'], 20, 1);

        // Validation and persistence for mailbox settings form.
        \Eventy::addFilter('mailbox.settings_validator', [$this, 'mailboxSettingsValidator'], 20, 3);
        \Eventy::addAction('mailbox.settings_before_save', [$this, 'mailboxSettingsBeforeSave'], 20, 2);

        // Auto-assign on incoming customer conversation.
        \Eventy::addAction('conversation.created_by_customer', [$this, 'onConversationCreatedByCustomer'], 20, 3);
    }

    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../Config/config.php', MAILBOXAUTODISTRIBUTOR_MODULE);

        // Bind services.
        $this->app->singleton(\Modules\MailboxAutoDistributor\Services\Assigner::class, function () {
            return new \Modules\MailboxAutoDistributor\Services\Assigner();
        });

        $this->app->singleton(\Modules\MailboxAutoDistributor\Services\PendingProcessor::class, function ($app) {
            return new \Modules\MailboxAutoDistributor\Services\PendingProcessor($app->make(\Modules\MailboxAutoDistributor\Services\Assigner::class));
        });

        if ($this->app->runningInConsole()) {
            $this->commands([
                \Modules\MailboxAutoDistributor\Console\Commands\ProcessPendingAssignments::class,
            ]);
        }
    }

    public function mailboxSettingsSection($mailbox)
    {
        if (!\Auth::check()) {
            return;
        }
        // Only allow users who can update mailbox settings.
        if (!\Auth::user()->can('updateSettings', $mailbox)) {
            return;
        }

        $meta = $mailbox->meta[MAILBOXAUTODISTRIBUTOR_MODULE] ?? [];

        echo view(MAILBOXAUTODISTRIBUTOR_MODULE . '::partials/mailbox_settings', [
            'mailbox' => $mailbox,
            'mad' => is_array($meta) ? $meta : [],
        ])->render();
    }

    public function mailboxSettingsValidator($validator, $mailbox, $request)
    {
        // Only validate if the user can update settings (same as core gate).
        if (!\Auth::check() || !\Auth::user()->can('updateSettings', $mailbox)) {
            return $validator;
        }

        $enabled = (bool)$request->input('mad_enabled');

        if ($enabled) {
            $validator->after(function ($validator) use ($request) {
                $users = $request->input('mad_users', []);
                if (!is_array($users) || !count($users)) {
                    $validator->errors()->add('mad_users', __('Please select at least one agent.'));
                }

                $mode = $request->input('mad_mode');
                if (!in_array($mode, ['round_robin', 'least_open'], true)) {
                    $validator->errors()->add('mad_mode', __('Please choose a valid distribution mode.'));
                }
            });
        }

        return $validator;
    }

    public function mailboxSettingsBeforeSave($mailbox, $request)
    {
        if (!\Auth::check() || !\Auth::user()->can('updateSettings', $mailbox)) {
            return;
        }

        $enabled = (bool)$request->input('mad_enabled');
        $mode = $request->input('mad_mode', 'round_robin');
        $users = $request->input('mad_users', []);

        $deferEnabled = (bool)$request->input('mad_defer_enabled');
        $deferMinutes = (int)$request->input('mad_defer_minutes', 5);
        $deferMinutes = max(1, min(60, $deferMinutes));
        $webFallback = (bool)$request->input('mad_web_fallback');

        $stickyEnabled = (bool)$request->input('mad_sticky_enabled');
        $stickyDays = (int)$request->input('mad_sticky_days', 60);
        $stickyDays = max(1, min(365, $stickyDays));

        $excludeTags = (string)$request->input('mad_exclude_tags', '');

        $fallbackUserId = (int)$request->input('mad_fallback_user_id', 0);

        $auditEnabled = (bool)$request->input('mad_audit_enabled');

        if (!is_array($users)) {
            $users = [];
        }

        // Normalize IDs.
        $users = array_values(array_unique(array_map('intval', $users)));
        $users = array_filter($users, function ($id) {
            return $id > 0;
        });

        $meta = $mailbox->meta[MAILBOXAUTODISTRIBUTOR_MODULE] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $meta['enabled'] = $enabled ? 1 : 0;
        $meta['mode'] = in_array($mode, ['round_robin', 'least_open'], true) ? $mode : 'round_robin';
        $meta['users'] = $users;

        $meta['defer_enabled'] = $deferEnabled ? 1 : 0;
        $meta['defer_minutes'] = $deferMinutes;
        $meta['web_fallback'] = $webFallback ? 1 : 0;

        $meta['sticky_enabled'] = $stickyEnabled ? 1 : 0;
        $meta['sticky_days'] = $stickyDays;

        $meta['exclude_tags'] = trim($excludeTags);

        $meta['fallback_user_id'] = $fallbackUserId > 0 ? $fallbackUserId : 0;

        $meta['audit_enabled'] = $auditEnabled ? 1 : 0;

        // If user list changed and last_assigned is no longer present, reset pointer.
        if (!empty($meta['last_assigned_user_id']) && $users && !in_array((int)$meta['last_assigned_user_id'], $users, true)) {
            $meta['last_assigned_user_id'] = 0;
        }

        $mailbox->setMetaParam(MAILBOXAUTODISTRIBUTOR_MODULE, $meta);
    }

    public function onConversationCreatedByCustomer($conversation, $thread, $customer)
    {
        // Optional "web fallback" processing for deferred assignments (for installs without cron).
        try {
            $mailbox = \App\Mailbox::find((int)$conversation->mailbox_id);
            if ($mailbox) {
                $meta = $mailbox->meta[MAILBOXAUTODISTRIBUTOR_MODULE] ?? [];
                if (is_array($meta) && !empty($meta['defer_enabled']) && !empty($meta['web_fallback'])) {
                    $key = 'mad_web_fallback_process';
                    if (\Cache::add($key, 1, 60)) {
                        /** @var \Modules\MailboxAutoDistributor\Services\PendingProcessor $processor */
                        $processor = app(\Modules\MailboxAutoDistributor\Services\PendingProcessor::class);
                        $processor->processDue(20);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore.
        }

        /** @var \Modules\MailboxAutoDistributor\Services\Assigner $assigner */
        $assigner = app(\Modules\MailboxAutoDistributor\Services\Assigner::class);
        $assigner->assignIfEnabled($conversation);
    }

    protected function registerTranslations()
    {
        $langPath = resource_path('lang/modules/' . MAILBOXAUTODISTRIBUTOR_MODULE);

        if (is_dir($langPath)) {
            $this->loadTranslationsFrom($langPath, MAILBOXAUTODISTRIBUTOR_MODULE);
        } else {
            $this->loadTranslationsFrom(__DIR__ . '/../Resources/lang', MAILBOXAUTODISTRIBUTOR_MODULE);
        }
    }

    protected function registerViews()
    {
        $viewPath = resource_path('views/modules/' . MAILBOXAUTODISTRIBUTOR_MODULE);
        $sourcePath = __DIR__ . '/../Resources/views';

        $this->loadViewsFrom([$viewPath, $sourcePath], MAILBOXAUTODISTRIBUTOR_MODULE);
    }
}
