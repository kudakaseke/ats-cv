<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(): View
    {
        return view('welcome');
    }

    public function pricing(): View
    {
        return view('pricing', ['plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->get()]);
    }
}
