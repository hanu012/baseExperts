{{-- New-password form, shared by the Laravel and Firebase reset links.
     $firstTime: the account has never chosen its own password (an emailed
     "set your password" link), so it is worded as setting, not resetting. --}}
@extends('auth.layout')

@php($firstTime = $firstTime ?? false)

@section('title', $firstTime ? 'Set your password' : 'Set a new password')
@section('subtitle', $firstTime ? 'Set your password' : 'Set a new password')

@section('content')
    <p class="muted">
        @if ($firstTime)
            Welcome! Choose a password for
            <span class="email">{{ $email }}</span>.
            You will use it to sign in to the app.
        @else
            Choose a new password for
            <span class="email">{{ $email }}</span>.
            You will use it to sign in to the app.
        @endif
    </p>

    <form method="POST" action="{{ $action }}">
        @csrf

        @foreach ($hidden as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach

        <label for="password">{{ $firstTime ? 'Password' : 'New password' }}</label>
        <input id="password" type="password" name="password" required autocomplete="new-password" minlength="8">

        <label for="password_confirmation">{{ $firstTime ? 'Confirm password' : 'Confirm new password' }}</label>
        <input id="password_confirmation" type="password" name="password_confirmation" required autocomplete="new-password" minlength="8">

        @if ($errors->any())
            <ul class="errors">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <button type="submit">{{ $firstTime ? 'Save password' : 'Save new password' }}</button>
    </form>
@endsection
