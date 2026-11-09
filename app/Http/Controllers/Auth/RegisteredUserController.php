<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): View
    {
        return view('auth.register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
        ]);
        $user->forceFill(['password_set_at' => now()])->save();

        if (! $request->session()->has('url.intended')) {
            $request->session()->put('url.intended', route('builder.start', ['questions' => 1]));
        }

        Auth::login($user);
        $request->session()->regenerate();

        try {
            event(new Registered($user));
        } catch (\Throwable $exception) {
            report($exception);

            return redirect()->route('verification.notice')->withErrors([
                'delivery' => 'We could not send your verification code. Please try resending it shortly.',
            ]);
        }

        return redirect()->route('verification.notice')->with('status', 'verification-code-sent');
    }
}
