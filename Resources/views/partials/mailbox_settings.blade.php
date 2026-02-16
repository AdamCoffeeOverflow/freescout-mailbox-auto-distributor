{{-- Mailbox Auto-Distributor settings (mailbox-scoped) --}}

@php
    $enabled = (int)old('mad_enabled', $mad['enabled'] ?? 0);
    $mode = old('mad_mode', $mad['mode'] ?? 'round_robin');

    $selected_users = old('mad_users', $mad['users'] ?? []);
    if (!is_array($selected_users)) {
        $selected_users = [];
    }

    $defer_enabled = (int)old('mad_defer_enabled', $mad['defer_enabled'] ?? 0);
    $defer_minutes = (int)old('mad_defer_minutes', $mad['defer_minutes'] ?? 5);
    $defer_minutes = max(1, min(60, $defer_minutes));
    $web_fallback = (int)old('mad_web_fallback', $mad['web_fallback'] ?? 0);

    $sticky_enabled = (int)old('mad_sticky_enabled', $mad['sticky_enabled'] ?? 0);
    $sticky_days = (int)old('mad_sticky_days', $mad['sticky_days'] ?? 60);
    $sticky_days = max(1, min(365, $sticky_days));

    $exclude_tags = old('mad_exclude_tags', $mad['exclude_tags'] ?? '');

    $fallback_user_id = (int)old('mad_fallback_user_id', $mad['fallback_user_id'] ?? 0);

    $override_default_assignee = (int)old('mad_override_default_assignee', $mad['override_default_assignee'] ?? 1);

    $audit_enabled = (int)old('mad_audit_enabled', $mad['audit_enabled'] ?? 0);

    $mailbox_users = $mailbox->users()->orderBy('first_name')->orderBy('last_name')->get();
@endphp

<div class="form-group">
    <div class="col-sm-6 col-sm-offset-2">
        <h3 class="margin-top-0 margin-bottom-5">{{ __('Mailbox Auto-Distributor') }}</h3>
        <p class="block-help margin-top-0 margin-bottom-0">{{ __('Automatically assigns new customer conversations in this mailbox to a selected agent pool.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Status') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_enabled" value="1" @if($enabled) checked="checked" @endif>
                <span>{{ __('Enable auto-distribution for this mailbox') }}</span>
            </label>
        </div>
    </div>
</div>

<div id="mad_settings_block" @if(!$enabled) class="hidden" @endif>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Distribution mode') }}</label>
    <div class="col-sm-6">
    <select class="form-control input-sized" name="mad_mode">
        <option value="round_robin" @if($mode === 'round_robin') selected="selected" @endif>{{ __('Round-robin (rotate)') }}</option>
        <option value="least_open" @if($mode === 'least_open') selected="selected" @endif>{{ __('Least open (even by workload)') }}</option>
    </select>
    <p class="block-help margin-bottom-0">{{ __('Least open counts Active + Pending conversations assigned to each agent in this mailbox.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Eligible agents') }}</label>
    <div class="col-sm-6 control-padded">
        <div id="mad_users_block">
        <p class="block-help margin-top-0">{{ __('Select the agents who should receive new conversations for this mailbox.') }}</p>
        <fieldset>
            <div class="row">
                @foreach($mailbox_users as $u)
                    @php $uid = (int)$u->id; @endphp
                    <div class="col-sm-6" style="padding-left:0;">
                        <div class="checkbox" style="margin-top:0;">
                            <label for="mad-user-{{ $uid }}" style="font-weight: normal;">
                                <input id="mad-user-{{ $uid }}" type="checkbox" name="mad_users[]" value="{{ $uid }}" @if(in_array($uid, $selected_users)) checked="checked" @endif>
                                {{ $u->getFullName() }} <span class="text-muted">({{ $u->email }})</span>
                            </label>
                        </div>
                    </div>
                @endforeach
            </div>
        </fieldset>
        </div>
    </div>
</div>

<hr>

<div class="form-group">
    <div class="col-sm-6 col-sm-offset-2">
        <h4 class="margin-top-10 margin-bottom-5">{{ __('Mailbox defaults') }}</h4>
        <p class="block-help margin-top-0 margin-bottom-0">{{ __('If the mailbox has a default assignee set, it may assign tickets before this module runs. Enable the option below to override that default with the pool distribution.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Override default assignee') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_override_default_assignee" value="1" @if($override_default_assignee) checked="checked" @endif>
                <span>{{ __('Override mailbox default assignee when module is enabled') }}</span>
            </label>
        </div>
        <p class="block-help margin-bottom-0">{{ __('This does not override manual assignments made by agents. For Workflows, use Workflows-first (defer) above.') }}</p>
    </div>
</div>

<hr>

<div class="form-group">
    <div class="col-sm-6 col-sm-offset-2">
        <h4 class="margin-top-10 margin-bottom-5">{{ __('Compatibility') }}</h4>
        <p class="block-help margin-top-0 margin-bottom-0">{{ __('If you use the Workflows module (or other automation) for assignment, enable Workflows-first to let it assign first. Auto-Distributor will only assign if still unassigned after a short delay.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Workflows-first') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_defer_enabled" value="1" @if($defer_enabled) checked="checked" @endif>
                <span>{{ __('Defer assignment (let Workflows assign first)') }}</span>
            </label>
        </div>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Defer minutes') }}</label>
    <div class="col-sm-6">
        <input type="number" min="1" max="60" class="form-control input-sized" name="mad_defer_minutes" value="{{ $defer_minutes }}">
        <p class="block-help margin-bottom-0">{{ __('Recommended: 5 minutes. Requires a cron job to process pending assignments (see README).') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('No cron') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_web_fallback" value="1" @if($web_fallback) checked="checked" @endif>
                <span>{{ __('Web fallback processing') }}</span>
            </label>
        </div>
        <p class="block-help margin-bottom-0">{{ __('If you cannot run cron, pending assignments will be processed opportunistically when new customer tickets arrive. Cron is still recommended for best reliability.') }}</p>
    </div>
</div>

<hr>

<div class="form-group">
    <div class="col-sm-6 col-sm-offset-2">
        <h4 class="margin-top-10 margin-bottom-5">{{ __('Smarter routing') }}</h4>
        <p class="block-help margin-top-0 margin-bottom-0">{{ __('Sticky assignment keeps continuity by assigning follow-up tickets from the same customer with the same subject to the same agent.') }}</p>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Sticky') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_sticky_enabled" value="1" @if($sticky_enabled) checked="checked" @endif>
                <span>{{ __('Sticky assignment (same customer + same subject)') }}</span>
            </label>
        </div>
    </div>
</div>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Lookback (days)') }}</label>
    <div class="col-sm-6">
        <input type="number" min="1" max="365" class="form-control input-sized" name="mad_sticky_days" value="{{ $sticky_days }}">
    </div>
</div>

<hr>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Exclusions') }}</label>
    <div class="col-sm-6">
        <input type="text" class="form-control input-sized" name="mad_exclude_tags" value="{{ $exclude_tags }}" placeholder="vip, billing, do-not-auto-assign">
        <p class="block-help margin-bottom-0">{{ __('Skip auto-assignment if the conversation has any of these tags (comma-separated). Useful when Workflows tags special cases (VIP, billing, etc.).') }}</p>
    </div>
</div>

<hr>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Fallback assignee') }}</label>
    <div class="col-sm-6">
    <select class="form-control input-sized" name="mad_fallback_user_id">
        <option value="0">{{ __('Leave unassigned') }}</option>
        @foreach($mailbox_users as $u)
            @php $uid = (int)$u->id; @endphp
            <option value="{{ $uid }}" @if($fallback_user_id === $uid) selected="selected" @endif>{{ $u->getFullName() }}</option>
        @endforeach
    </select>
    <p class="block-help margin-bottom-0">{{ __('If no eligible agent is available, optionally assign to a fallback user. Otherwise the conversation remains unassigned.') }}</p>
    </div>
</div>

<hr>

<div class="form-group">
    <label class="col-sm-2 control-label">{{ __('Diagnostics') }}</label>
    <div class="col-sm-6">
        <div class="controls">
            <label class="checkbox inline plain" style="padding-left:0;">
                <input type="checkbox" name="mad_audit_enabled" value="1" @if($audit_enabled) checked="checked" @endif>
                <span>{{ __('Enable lightweight assignment audit log') }}</span>
            </label>
        </div>
        <p class="block-help margin-bottom-0">{{ __('Stores the last 200 assignment decisions per mailbox (assigned/enqueued/skipped).') }}</p>
    </div>
</div>

</div>
