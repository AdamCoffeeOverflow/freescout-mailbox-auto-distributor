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

        // Page-specific JS (CSP-safe external script).
        \Eventy::addFilter('javascripts', function ($javascripts) {
            try {
                if (\Route::currentRouteName() === 'mailboxes.update') {
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

        // If user list changed and last_assigned is no longer present, reset pointer.
        if (!empty($meta['last_assigned_user_id']) && $users && !in_array((int)$meta['last_assigned_user_id'], $users, true)) {
            $meta['last_assigned_user_id'] = 0;
        }

        $mailbox->setMetaParam(MAILBOXAUTODISTRIBUTOR_MODULE, $meta);
    }

    public function onConversationCreatedByCustomer($conversation, $thread, $customer)
    {
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
