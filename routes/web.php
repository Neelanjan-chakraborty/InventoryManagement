<?php

use App\Http\Controllers\Admin\UserAccessController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\LoanController;
use App\Http\Controllers\RenewalController;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/', fn () => Auth::check() ? redirect()->route('dashboard') : view('auth.login'));
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'loginForm'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:5,1')->name('login.store');
    Route::get('/register', [AuthController::class, 'registerForm'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1')->name('register.store');
});

Route::post('/logout', [AuthController::class, 'logout'])->middleware('auth')->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
});

Route::middleware(['auth', 'role:student'])->group(function () {
    Route::get('/my-loans', [LoanController::class, 'myLoans'])->name('my-loans');
    Route::post('/my-loans/{loan}/renew', [LoanController::class, 'requestRenewal'])->name('my-loans.renew');
});

Route::middleware(['auth', 'role:coordinator,admin'])->group(function () {
    Route::get('/inventory/export', [InventoryController::class, 'export'])->name('inventory.export');
    Route::get('/inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::post('/inventory', [InventoryController::class, 'store'])->name('inventory.store');
    Route::put('/inventory/{item}', [InventoryController::class, 'update'])->name('inventory.update');
    Route::post('/inventory/{item}/status', [InventoryController::class, 'setActive'])->name('inventory.status');
    Route::post('/inventory/{item}/assets', [InventoryController::class, 'storeAsset'])->name('inventory.assets.store');
    Route::post('/inventory/{item}/adjustments', [InventoryController::class, 'adjust'])->name('inventory.adjust');
    Route::post('/assets/{asset}/condition', [InventoryController::class, 'updateAsset'])->name('assets.condition');
    Route::post('/locations', [InventoryController::class, 'storeLocation'])->name('locations.store');
    Route::put('/locations/{location}', [InventoryController::class, 'updateLocation'])->name('locations.update');

    Route::get('/loans', [LoanController::class, 'index'])->name('loans.index');
    Route::get('/loans/export', [LoanController::class, 'export'])->name('loans.export');
    Route::post('/loans', [LoanController::class, 'store'])->name('loans.store');
    Route::post('/loan-lines/{line}/returns', [LoanController::class, 'receiveReturn'])->name('loan-lines.return');
    Route::get('/renewals', [RenewalController::class, 'index'])->name('renewals.index');
    Route::post('/renewals/{renewal}/review', [RenewalController::class, 'review'])->name('renewals.review');
});

Route::middleware(['auth', 'role:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/users', [UserAccessController::class, 'index'])->name('users.index');
    Route::put('/users/{user}/role', [UserAccessController::class, 'update'])->name('users.role');
});
