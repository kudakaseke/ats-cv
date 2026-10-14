<?php

namespace App\Livewire;

use App\Services\PlanService;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class Dashboard extends Component
{
    public function render()
    {
        $user = Auth::user();
        $plans = app(PlanService::class);

        return view('livewire.dashboard', [
            'resumeCount' => $user->resumes()->count(),
            'analysisCount' => $user->analyses()->where('status', 'completed')->count(),
            'latestScore' => $user->analyses()->where('status', 'completed')->latest()->value('score'),
            'remaining' => $plans->remainingAnalyses($user),
            'plan' => $plans->current($user),
            'resumes' => $user->resumes()->latest('updated_at')->limit(5)->get(),
            'analyses' => $user->analyses()->with('resume')->latest()->limit(5)->get(),
        ]);
    }
}
