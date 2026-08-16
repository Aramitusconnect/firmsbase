@extends('auth.request-card', [
    'heading' => __('Request client access'),
    'lede' => __('Your law firm grants Client Portal access. Send your details and we will pass them to the firm you name so they can verify you and issue an invitation.'),
    'action' => route('client-portal.register.store'),
    'submitLabel' => __('Request client access'),
    'loginUrl' => \Filament\Facades\Filament::getPanel('client-portal')->getLoginUrl(),
    'receivedBody' => __('Your law firm must verify and link you before Client Portal access is created. When they do, you will receive an invitation email with a link to set your password.'),
])

@section('fields')
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

    <label for="email">{{ __('Email') }}</label>
    <input id="email" name="email" type="email" required maxlength="255" value="{{ old('email') }}">

    <label for="firm_name">{{ __('Law firm or attorney name') }}</label>
    <input id="firm_name" name="firm_name" type="text" required maxlength="255" value="{{ old('firm_name') }}">

    <label for="phone">{{ __('Phone (optional)') }}</label>
    <input id="phone" name="phone" type="tel" maxlength="50" value="{{ old('phone') }}">
@endsection
