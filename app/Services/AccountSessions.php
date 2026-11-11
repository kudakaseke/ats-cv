<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;

class AccountSessions
{
    public function stamp(User $user): void
    {
        Session::put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));
    }

    public function track(Request $request): void
    {
        if ($user = $request->user()) {
            DB::table('account_sessions')->upsert([[
                'id' => $request->session()->getId(), 'user_id' => $user->id,
                'driver' => config('session.driver'), 'last_activity' => time(),
            ]], ['id'], ['user_id', 'driver', 'last_activity']);
        }
    }

    public function erase(User $user): void
    {
        foreach (DB::table('account_sessions')->where('user_id', $user->id)->get() as $session) {
            // Driver names originate in server configuration, never request input.
            Session::driver($session->driver)->getHandler()->destroy($session->id);
        }
        // Also remove database sessions created before the registry was deployed.
        DB::connection(config('session.connection'))->table(config('session.table', 'sessions'))
            ->where('user_id', $user->id)->delete();
        DB::table('account_sessions')->where('user_id', $user->id)->delete();
        Password::broker()->deleteToken($user);
    }
}
