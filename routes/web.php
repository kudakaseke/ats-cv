<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BillingManagementController;
use App\Http\Controllers\CreditBillingController;
use App\Http\Controllers\CvBuilderController;
use App\Http\Controllers\CvCheckerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\RecordAccountActivity;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::get('/', HomeController::class)->name('home');
Route::view('/features', 'features')->name('features');
Route::get('/pricing', [HomeController::class, 'pricing'])->name('pricing');
Route::view('/about', 'about')->name('about');
Route::view('/ats-explained', 'ats-explained')->name('ats.explained');
Route::get('/get-started', [CvBuilderController::class, 'show'])->name('builder.start');
Route::post('/get-started/draft', [CvBuilderController::class, 'save'])->middleware(['throttle:builder-save'])->block(10, 3)->name('builder.save');
Route::post('/get-started/export', [CvBuilderController::class, 'export'])->middleware(['auth', 'verified', 'throttle:cv-export'])->name('builder.export');
Route::post('/get-started/import', [CvBuilderController::class, 'import'])->middleware(['throttle:builder-import'])->name('builder.import');
Route::get('/get-started/continue', fn () => redirect()->route('builder.start'))->middleware(['auth', 'verified'])->name('builder.continue');
Route::get('/get-started/finish', [CvBuilderController::class, 'finish'])->middleware(['auth', 'verified'])->block(10, 3)->name('builder.finish');
Route::get('/cv/{resume}/edit', [CvBuilderController::class, 'edit'])->middleware(['auth', 'verified', 'throttle:workspace-read'])->name('builder.edit');
Route::delete('/cv/{resume}', [CvBuilderController::class, 'destroy'])->middleware(['auth', 'verified', 'throttle:auth-password'])->name('builder.destroy');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/refund-policy', 'legal.refund')->name('refund');
Route::view('/contact', 'legal.contact')->name('contact');
Route::post('/billing/paynow/notify/{reference}', [BillingController::class, 'paynowNotify'])
    ->withoutMiddleware([ValidateCsrfToken::class, StartSession::class,
        ShareErrorsFromSession::class, RecordAccountActivity::class])
    ->middleware('throttle:payment-notify')->name('billing.paynow.notify');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', 'throttle:workspace-read'])->name('dashboard');

Route::middleware(['auth', 'verified', 'throttle:workspace-read'])->group(function () {
    Route::get('/account/activity', ActivityController::class)->name('account.activity');
    Route::get('/ai/credits', [CvCheckerController::class, 'credits'])->name('ai.credits');
    Route::post('/credits/topup', [CreditBillingController::class, 'topup'])->middleware('throttle:payment-checkout')->name('credits.topup');
    Route::post('/billing/{provider}/{plan}', [BillingController::class, 'start'])->middleware('throttle:payment-checkout')->name('billing.start');
    Route::get('/payments/{reference}', [BillingController::class, 'show'])->middleware('throttle:payment-return')->name('billing.show');
    Route::get('/billing/paynow/return/{reference}', [BillingController::class, 'show'])->middleware('throttle:payment-return')->name('billing.paynow.return');
    Route::post('/payments/{reference}/check', [BillingController::class, 'check'])->middleware('throttle:payment-check')->name('billing.check');
    Route::post('/checker/rewrite', [CvCheckerController::class, 'rewrite'])->middleware('throttle:5,1')->name('checker.rewrite');
    Route::post('/checker/scan', [CvCheckerController::class, 'scan'])->middleware('throttle:ai-analysis')->name('checker.scan');
    Route::post('/checker/detect', [CvCheckerController::class, 'detect'])->middleware('throttle:30,1')->name('checker.detect');
    Route::post('/checker/upload', [CvCheckerController::class, 'upload'])->middleware('throttle:builder-import')->name('checker.upload');
    Route::post('/checker/edit', [CvCheckerController::class, 'edit'])->block(10, 3)->name('checker.edit');
    Route::get('/workspace/{module}', [WorkspaceController::class, 'module'])->name('workspace.module');
    Route::get('/analyses/{analysis}', [WorkspaceController::class, 'analysis'])->name('workspace.analysis');
    Route::get('/subscription', [WorkspaceController::class, 'subscription'])->name('subscription');
    Route::get('/payments/{reference}/receipt', [BillingManagementController::class, 'receipt'])->name('billing.receipt');
    Route::post('/subscriptions/{subscription}/cancel-renewal', [BillingManagementController::class, 'cancel'])->middleware('throttle:6,1')->name('billing.cancel-renewal');
    Route::get('/admin/billing', [BillingManagementController::class, 'admin'])->name('admin.billing');
    Route::post('/admin/billing/{reference}/reconcile', [BillingManagementController::class, 'reconcile'])->middleware('throttle:6,1')->name('admin.billing.reconcile');
    Route::post('/admin/billing/{reference}/review', [BillingManagementController::class, 'review'])->middleware('throttle:6,1')->name('admin.billing.review');
    Route::get('/subscription/{plan}/payment-method', [WorkspaceController::class, 'paymentMethods'])->name('subscription.payment-methods');
});

Route::middleware(['auth', 'throttle:workspace-read'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:auth-password')->name('profile.destroy');
});

require __DIR__.'/auth.php';
