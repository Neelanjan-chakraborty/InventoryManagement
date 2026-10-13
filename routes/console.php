<?php

use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('inventory:promote-admin {email}', function (string $email) {
    $user = User::query()->where('email', $email)->first();

    if (! $user) {
        $this->error('No registered user was found with that email.');

        return self::FAILURE;
    }

    $user->update(['role' => 'admin']);
    $this->info("{$user->email} now has administrator access.");

    return self::SUCCESS;
})->purpose('Grant administrator access to an existing registered account');
