<?php

use App\Http\Controllers\ActivityController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\CreditBillingController;
use App\Http\Controllers\CvBuilderController;
use App\Http\Controllers\CvCheckerController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PayPalController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

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
Route::post('/billing/paynow/notify/{reference}', [BillingController::class, 'paynowNotify'])->middleware('throttle:60,1')->name('billing.paynow.notify');
Route::post('/billing/paypal/notify', [BillingController::class, 'paypalNotify'])->middleware('throttle:60,1')->name('billing.paypal.notify');
Route::post('/paypal/webhook', [PayPalController::class, 'handleWebhook'])->middleware('throttle:60,1')->name('paypal.webhook');
Route::post('/webhooks/paystack', [CreditBillingController::class, 'webhook'])->middleware('throttle:120,1')->name('paystack.webhook');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified', 'throttle:workspace-read'])->name('dashboard');

Route::middleware(['auth', 'verified', 'throttle:workspace-read'])->group(function () {
    Route::get('/paystack/callback', [CreditBillingController::class, 'callback'])->middleware('throttle:payment-return')->name('paystack.callback');
    Route::get('/account/activity', ActivityController::class)->name('account.activity');
    Route::get('/ai/credits', [CvCheckerController::class, 'credits'])->name('ai.credits');
    Route::post('/credits/topup', [CreditBillingController::class, 'topup'])->middleware('throttle:6,1')->name('credits.topup');
    Route::post('/paystack/subscribe/{plan}', [CreditBillingController::class, 'subscribe'])->middleware('throttle:6,1')->name('paystack.subscribe');
    Route::post('/paystack/{reference}/cancel', [CreditBillingController::class, 'cancel'])->middleware('throttle:6,1')->name('paystack.cancel');
    Route::post('paypal/checkout', [PayPalController::class, 'checkout'])->middleware('throttle:6,1')->name('paypal.checkout');
    Route::get('paypal/success', [PayPalController::class, 'success'])->middleware('throttle:payment-return')->name('paypal.success');
    Route::get('paypal/cancel', [PayPalController::class, 'cancel'])->name('paypal.cancel');
    Route::post('/billing/{provider}/{plan}', [BillingController::class, 'start'])->middleware('throttle:6,1')->name('billing.start');
    Route::get('/payments/{reference}', [BillingController::class, 'show'])->middleware('throttle:payment-return')->name('billing.show');
    Route::post('/payments/{reference}/check', [BillingController::class, 'check'])->middleware('throttle:12,1')->name('billing.check');
    Route::post('/payments/{reference}/cancel-paypal', [BillingController::class, 'cancelPaypal'])->middleware('throttle:6,1')->name('billing.paypal.cancel');
    Route::post('/checker/rewrite', [CvCheckerController::class, 'rewrite'])->middleware('throttle:5,1')->name('checker.rewrite');
    Route::get('/checker/operations/{operation}', [CvCheckerController::class, 'operation'])->whereNumber('operation')->name('checker.operation');
    Route::post('/checker/scan', [CvCheckerController::class, 'scan'])->middleware('throttle:ai-analysis')->name('checker.scan');
    Route::post('/checker/detect', [CvCheckerController::class, 'detect'])->middleware('throttle:30,1')->name('checker.detect');
    Route::post('/checker/upload', [CvCheckerController::class, 'upload'])->middleware('throttle:builder-import')->name('checker.upload');
    Route::post('/checker/edit', [CvCheckerController::class, 'edit'])->block(10, 3)->name('checker.edit');
    Route::get('/workspace/{module}', [WorkspaceController::class, 'module'])->name('workspace.module');
    Route::get('/analyses/{analysis}', [WorkspaceController::class, 'analysis'])->name('workspace.analysis');
    Route::get('/subscription', [WorkspaceController::class, 'subscription'])->name('subscription');
    Route::get('/subscription/{plan}/payment-method', [WorkspaceController::class, 'paymentMethods'])->name('subscription.payment-methods');
});

Route::middleware(['auth', 'throttle:workspace-read'])->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->middleware('throttle:auth-password')->name('profile.destroy');
});

require __DIR__.'/auth.php';
