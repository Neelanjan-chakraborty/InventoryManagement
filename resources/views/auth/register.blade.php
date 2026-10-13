@extends('layouts.app')

@section('title', 'Student registration')

@section('content')
<section class="auth-form-wrap auth-register">
    <span class="eyebrow">STUDENT ACCESS</span>
    <h2>Create your account</h2>
    <p class="muted">Register with your department details.</p>
    @if($errors->any()) <div class="alert alert-error">{{ $errors->first() }}</div> @endif
    <form method="POST" action="{{ route('register.store') }}" class="form-stack">
        @csrf
        <label>Full name<input name="name" value="{{ old('name') }}" autocomplete="name" required></label>
        <div class="form-grid">
            <label>Enrollment number<input name="enrollment_number" value="{{ old('enrollment_number') }}" required></label>
            <label>Contact number<input name="contact" value="{{ old('contact') }}" autocomplete="tel"></label>
        </div>
        <label>Email address<input type="email" name="email" value="{{ old('email') }}" autocomplete="email" required></label>
        <div class="form-grid">
            <label>Programme<input name="programme" value="{{ old('programme') }}" required></label>
            <label>Semester<input name="semester" value="{{ old('semester') }}" required></label>
        </div>
        <div class="form-grid">
            <label>Password<input type="password" name="password" autocomplete="new-password" required></label>
            <label>Confirm password<input type="password" name="password_confirmation" autocomplete="new-password" required></label>
        </div>
        <p class="field-hint">Use at least 10 characters with uppercase, lowercase and a number.</p>
        <button class="button button-primary button-wide" type="submit">Create student account <span>→</span></button>
    </form>
    <p class="auth-switch">Already registered? <a href="{{ route('login') }}">Sign in</a></p>
</section>
@endsection
