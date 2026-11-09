<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class SetPasswordController extends Controller
{
    public function create(Request $request)
    {
        if (! $request->user()->canSetInitialPassword()) {
            return to_route('profile.edit');
        }
        return view('auth.set-password', ['user' => $request->user()]);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->canSetInitialPassword(), 403);
        $validated = $request->validate([
            'email' => ['required', 'string', Rule::in([$request->user()->email])],
            'password' => ['required', Password::defaults(), 'confirmed'],
        ]);
        DB::transaction(function () use ($request, $validated) {
            $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->canSetInitialPassword(), 403);
            $user->forceFill(['password' => Hash::make($validated['password']), 'password_set_at' => now(), 'password_setup_required' => false,
                'remember_token' => Str::random(60)])->save();
            if (config('session.driver') === 'database') {
                DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
                    ->where('user_id', $user->id)->where('id', '!=', $request->session()->getId())->delete();
            }
        }, 3);
        $request->user()->refresh();
        $request->session()->regenerate(true);

        return to_route('profile.edit')->with('status', 'password-updated');
    }
}
