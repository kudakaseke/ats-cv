<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\EmailVerificationCodeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(Request $request, EmailVerificationCodeService $codes): RedirectResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
        }

        $validated = $request->validate(['code' => ['required', 'string', 'regex:/\A[0-9]{6}\z/']]);
        $codes->verify($request->user(), $validated['code']);

        return redirect()->intended(route('dashboard', absolute: false).'?verified=1');
    }
}
