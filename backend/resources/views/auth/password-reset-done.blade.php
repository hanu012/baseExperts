@extends('auth.layout')

@php($firstTime = $firstTime ?? false)
@php($appLink = $appLink ?? null)

@section('title', $firstTime ? 'Password set' : 'Password changed')
@section('subtitle', 'All set')

@section('content')
    <div class="center">
        <div class="icon" style="color: var(--success)">✓</div>
        @if ($firstTime)
            <h2 style="margin: 0 0 8px">Password set</h2>
            <p class="muted">
                Your account is ready. Open the app and sign in with your
                email and this password.
            </p>
        @else
            <h2 style="margin: 0 0 8px">Password changed</h2>
            <p class="muted">
                Go back to the app and sign in with your new password.
                For your security, you have been signed out on every device.
            </p>
        @endif

        {{-- Opens the app at its sign-in screen. Works on the phone the app
             is installed on; anywhere else the text above says what to do. --}}
        @if ($appLink)
            <a href="{{ $appLink }}" class="button">Open the app</a>
        @endif
    </div>
@endsection
