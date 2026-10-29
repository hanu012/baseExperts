@extends('auth.layout')

@section('title', 'Link expired')
@section('subtitle', 'This link no longer works')

@section('content')
    <div class="center">
        <div class="icon" style="color: var(--error)">!</div>
        <h2 style="margin: 0 0 8px">This password link has expired or was already used</h2>
        <p class="muted">
            Open the app, tap <strong>Forgot password?</strong> on the sign-in
            screen and ask for a new link. Only the most recent link works.
            New account? You can also ask whoever set it up to resend it.
        </p>
    </div>
@endsection
