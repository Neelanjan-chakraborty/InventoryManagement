@extends('layouts.app')

@section('title', 'Sign in')

@section('content')
<section class="auth-form-wrap">
    <span class="eyebrow">WELCOME BACK</span>
    <h2>Sign in to your account</h2>
    <p class="muted">Use your department account to continue.</p>
    @if($errors->any()) <div class="alert alert-error">{{ $errors->first() }}</div> @endif
    <form method="POST" action="{{ route('login.store') }}" class="form-stack">
        @csrf
        <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required autofocus></label>
        <label>Password<input type="password" name="password" autocomplete="current-password" required></label>
        <label class="check-row"><input type="checkbox" name="remember" value="1"> Keep me signed in</label>
        <button class="button button-primary button-wide" type="submit">Sign in <span>→</span></button>
    </form>
    <p class="auth-switch">New student? <a href="{{ route('register') }}">Create an account</a></p>
</section>
@endsection
