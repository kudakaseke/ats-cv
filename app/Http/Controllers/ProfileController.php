<?php

namespace App\Http\Controllers;

use App\Http\Requests\ProfileUpdateRequest;
use App\Models\BillingPayment;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class ProfileController extends Controller
{
    /**
     * Display the user's profile form.
     */
    public function edit(Request $request): View
    {
        return view('profile.edit', [
            'user' => $request->user(),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->only('name'));

        $request->user()->save();

        return Redirect::route('profile.edit')->with('status', 'profile-updated');
    }

    /**
     * Delete the user's account.
     */
    public function destroy(Request $request): RedirectResponse
    {
        if (BillingPayment::where('user_id', $request->user()->id)->where('recurring', true)
            ->where(fn ($query) => $query->whereNull('recurring_status')->orWhereNotIn('recurring_status', ['CANCELLED', 'EXPIRED', 'SETUP_FAILED', 'DISABLED', 'COMPLETE']))->exists()) {
            throw ValidationException::withMessages(['password' => 'Contact support to resolve your existing automatic renewal before deleting your account.'])->errorBag('userDeletion');
        }
        $request->validateWithBag('userDeletion', [
            'password' => ['required', 'current_password'],
        ]);

        $user = $request->user();

        \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            app(\App\Services\AccountSessions::class)->erase($user);
            Auth::logout();
            $user->delete();
        });

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return Redirect::to('/');
    }
}
