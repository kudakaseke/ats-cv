<?php

use App\Http\Controllers\Admin\AdminAuthController;
use App\Http\Controllers\Admin\OverviewController;
use App\Http\Controllers\Admin\SectionController;
use App\Http\Controllers\FeedbackController;
use App\Http\Controllers\BillingManagementController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware('admin.ip')->group(function () {
    Route::get('login', [AdminAuthController::class, 'loginForm'])->name('login');
    Route::post('login', [AdminAuthController::class, 'login'])->middleware('throttle:admin-login')->name('login.store');

    Route::middleware(['admin.auth', 'admin.role', 'throttle:admin', 'audit.admin'])->group(function () {
        Route::get('2fa/setup', [AdminAuthController::class, 'setup'])->name('2fa.setup');
        Route::post('2fa/confirm', [AdminAuthController::class, 'confirmSetup'])->middleware('throttle:auth-password')->name('2fa.confirm');
        Route::get('2fa/challenge', [AdminAuthController::class, 'challenge'])->name('2fa.challenge');
        Route::post('2fa/verify', [AdminAuthController::class, 'verify'])->middleware('throttle:auth-password')->name('2fa.verify');
        Route::post('logout', [AdminAuthController::class, 'logout'])->name('logout');

        Route::middleware('2fa.confirmed')->group(function () {
            Route::get('dashboard', OverviewController::class)->name('dashboard');
            Route::get('section/{section}', [SectionController::class, 'show'])->whereIn('section', ['users','revenue','cv','ai','credits','accounts','reviews','system'])->name('section');
            Route::post('users/{user}/account', [SectionController::class, 'accountAction'])->middleware('throttle:6,1')->name('users.action');
            Route::post('feedback/{feedback}/moderate', [FeedbackController::class, 'moderate'])->middleware('throttle:10,1')->name('feedback.moderate');
            Route::get('billing', [BillingManagementController::class, 'admin'])->name('billing');
            Route::post('billing/{reference}/reconcile', [BillingManagementController::class, 'reconcile'])->middleware('throttle:6,1')->name('billing.reconcile');
            Route::post('billing/{reference}/review', [BillingManagementController::class, 'review'])->middleware('throttle:6,1')->name('billing.review');
        });
    });
});
