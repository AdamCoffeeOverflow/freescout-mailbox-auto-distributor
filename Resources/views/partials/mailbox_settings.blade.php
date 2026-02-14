{{-- Mailbox Auto-Distributor settings (mailbox-scoped) --}}

@php
    $enabled = (int)old('mad_enabled', $mad['enabled'] ?? 0);
    $mode = old('mad_mode', $mad['mode'] ?? 'round_robin');
    $selected_users = old('mad_users', $mad['users'] ?? []);
    if (!is_array($selected_users)) {
        $selected_users = [];
    }

    $mailbox_users = $mailbox->users()->orderBy('first_name')->orderBy('last_name')->get();
@endphp

<div class="margin-top-10">
    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Auto-Distributor') }}</label>
        <div class="col-sm-6">
            <label class="checkbox inline plain" style="margin-top:4px">
                <input type="checkbox" name="mad_enabled" value="1" @if($enabled) checked="checked" @endif>
                <span class="text-help">{{ __('Automatically assign new incoming conversations in this mailbox') }}</span>
            </label>
            <div class="help-block">
                {{ __('When enabled, new customer conversations are assigned to the selected agent pool using the chosen distribution mode.') }}
            </div>
        </div>
    </div>

    <div class="form-group @if(!$enabled) hidden @endif" id="mad_settings_block">
        <label class="col-sm-2 control-label">{{ __('Distribution') }}</label>
        <div class="col-sm-6">
            <select class="form-control input-sized" name="mad_mode">
                <option value="round_robin" @if($mode==='round_robin') selected="selected" @endif>{{ __('Round-robin (rotate)') }}</option>
                <option value="least_open" @if($mode==='least_open') selected="selected" @endif>{{ __('Least open (even by workload)') }}</option>
            </select>
            @include('partials/field_error', ['field'=>'mad_mode'])

            <div class="help-block">
                <div><strong>{{ __('Round-robin') }}:</strong> {{ __('Rotates assignments through the pool in order.') }}</div>
                <div><strong>{{ __('Least open') }}:</strong> {{ __('Assigns to the agent with the fewest open conversations in this mailbox (Active + Pending).') }}</div>
            </div>
        </div>
    </div>

    <div class="form-group @if(!$enabled) hidden @endif" id="mad_users_block">
        <label class="col-sm-2 control-label">{{ __('Agents') }}</label>
        <div class="col-sm-8">
            <div class="help-block" style="margin-top:0">
                {{ __('Select the agents eligible for auto-assignment in this mailbox.') }}
            </div>

            <div class="row" style="margin-top:6px">
                @foreach($mailbox_users as $u)
                    <div class="col-sm-6" style="margin-bottom:6px">
                        <label class="checkbox inline plain">
                            <input type="checkbox" name="mad_users[]" value="{{ $u->id }}" @if(in_array($u->id, $selected_users)) checked="checked" @endif>
                            <span>
                                {{ $u->getFullName() }}
                                <span class="text-help">&lt;{{ $u->email }}&gt;</span>
                                @if(!$u->isActive())
                                    <span class="label label-default" style="margin-left:6px">{{ __('Inactive') }}</span>
                                @endif
                            </span>
                        </label>
                    </div>
                @endforeach
            </div>

            @include('partials/field_error', ['field'=>'mad_users'])

            <div class="help-block">
                {{ __('Tip: keep the pool small and stable for predictable distribution.') }}
            </div>
        </div>
    </div>
</div>

{{-- Tiny progressive enhancement without inline script (CSP-safe): reuse existing main.js toggling pattern by data attributes --}}
<div class="hidden" data-mad-enabled="{{ $enabled ? 1 : 0 }}"></div>
