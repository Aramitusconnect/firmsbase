@extends('auth.request-card', [
    'heading' => __('Register your firm'),
    'lede' => __('Tell us about your firm and we will get your account set up. Your owner account is created by our team, and you will receive a setup invitation by email.'),
    'action' => route('firm.register.store'),
    'submitLabel' => __('Submit registration'),
    'loginUrl' => \Filament\Facades\Filament::getPanel('firm')->getLoginUrl(),
    'receivedHeading' => 'Registration received',
    'receivedBody' => __("We've received your firm's registration request. Check your email for confirmation. After verification, you'll receive a separate invitation to finish setting up your account."),
])

@section('fields')
    <label for="firm_name">{{ __('Firm name') }}</label>
    <input id="firm_name" name="firm_name" type="text" required maxlength="255" value="{{ old('firm_name') }}">

    <div class="row">
        <div>
            <label for="first_name">{{ __('First name') }}</label>
            <input id="first_name" name="first_name" type="text" required maxlength="100" value="{{ old('first_name') }}">
        </div>
        <div>
            <label for="last_name">{{ __('Last name') }}</label>
            <input id="last_name" name="last_name" type="text" required maxlength="100" value="{{ old('last_name') }}">
        </div>
    </div>

    <label for="email">{{ __('Work email') }}</label>
    <input id="email" name="email" type="email" required maxlength="255" value="{{ old('email') }}">
@endsection
