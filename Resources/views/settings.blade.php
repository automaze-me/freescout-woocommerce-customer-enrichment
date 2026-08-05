<form class="form-horizontal margin-top" method="POST" action="">
    {{ csrf_field() }}

    <h3 class="subheader">{{ __('Order Number Detection') }}</h3>

    <div class="form-group">
        <label class="col-sm-2 control-label">{{ __('Order number pattern') }}</label>
        <div class="col-sm-6">
            <input type="text" class="form-control input-sized-lg" name="settings[woocommercecustomerenrichment.pattern]"
                value="{{ old('settings.woocommercecustomerenrichment.pattern', $settings['woocommercecustomerenrichment.pattern']) }}">
            <p class="help-block">{{ __('Regular expression (without delimiters, case-insensitive) with one capture group for the order number. Applied to the subject and body of incoming messages. Leave empty to use the built-in default.') }}</p>
        </div>
    </div>

    <h3 class="subheader">{{ __('Profile Enrichment') }}</h3>

    @foreach ([
        'enrich_phone'   => [__('Phone numbers'), __('Add billing phone numbers from matched orders (existing numbers are never removed).')],
        'enrich_email'   => [__('Alternate emails'), __('Add the order billing email as an additional customer email when it differs.')],
        'enrich_name'    => [__('Name'), __('Fill in first/last name when the customer has none.')],
        'enrich_address' => [__('Company & address'), __('Fill in company, address, city, state, ZIP and country when empty.')],
        'enrich_photo'   => [__('Profile photo (Gravatar)'), __('Set the profile photo from Gravatar when one exists for any customer email. Existing photos are never replaced. Email hashes are sent to gravatar.com.')],
    ] as $key => $labels)
        <div class="form-group">
            <label class="col-sm-2 control-label">{{ $labels[0] }}</label>
            <div class="col-sm-6">
                <div class="controls">
                    <div class="onoffswitch-wrap">
                        <div class="onoffswitch">
                            <input type="checkbox" name="settings[woocommercecustomerenrichment.{{ $key }}]" value="1"
                                id="wcce_{{ $key }}" class="onoffswitch-checkbox"
                                @if (old('settings.woocommercecustomerenrichment.'.$key, $settings['woocommercecustomerenrichment.'.$key])) checked @endif>
                            <label class="onoffswitch-label" for="wcce_{{ $key }}"></label>
                        </div>
                    </div>
                </div>
                <p class="help-block">{{ $labels[1] }}</p>
            </div>
        </div>
    @endforeach

    <div class="form-group margin-top">
        <div class="col-sm-6 col-sm-offset-2">
            <button type="submit" class="btn btn-primary">{{ __('Save') }}</button>
        </div>
    </div>
</form>
