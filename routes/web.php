<?php

use App\Http\Controllers\CvBuilderController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/get-started', [CvBuilderController::class, 'show'])->name('builder.start');
Route::post('/get-started/draft', [CvBuilderController::class, 'save'])->middleware('throttle:builder-save')->block(10, 3)->name('builder.save');
Route::post('/get-started/import', [CvBuilderController::class, 'import'])->middleware('throttle:builder-import')->name('builder.import');
Route::get('/get-started/finish', [CvBuilderController::class, 'finish'])->middleware(['auth', 'verified'])->block(10, 3)->name('builder.finish');
Route::get('/cv/{resume}/edit', [CvBuilderController::class, 'edit'])->middleware(['auth', 'verified'])->name('builder.edit');
Route::view('/privacy', 'legal.privacy')->name('privacy');
Route::view('/terms', 'legal.terms')->name('terms');
Route::view('/contact', 'legal.contact')->name('contact');

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/workspace/{module}', [WorkspaceController::class, 'module'])->name('workspace.module');
    Route::get('/subscription', [WorkspaceController::class, 'subscription'])->name('subscription');
});

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';
