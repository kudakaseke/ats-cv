<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPlan;
use App\Services\PlanService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WorkspaceController extends Controller
{
    public function module(string $module): View|RedirectResponse
    {
        if ($module === 'builder') {
            return redirect()->route('builder.start', ['new' => 1]);
        }
        if ($module === 'resumes') {
            return view('workspace.resumes', ['resumes' => auth()->user()->resumes()->latest('updated_at')->paginate(12)]);
        }
        $modules = [
            'checker' => ['ATS CV Checker', 'Compare your CV with a job description', 'Phase 2', 'PDF and DOCX uploads, private text extraction, Gemini analysis, and transparent weighted scoring.'],
            'revamp' => ['Revamp My CV', 'Make your experience read at its best', 'Phase 3', 'AI-assisted rewriting with an editable review, separate versions, and no invented qualifications.'],
            'builder' => ['Create CV', 'A professional CV, built around you', 'Phase 4', 'A guided ten-step builder with automatic draft saving and consistent PDF and Word exports.'],
            'resumes' => ['My CVs', 'Your career documents, in one place', 'Phase 3', 'Private document storage, multiple versions, editing, duplication, and downloads.'],
            'history' => ['Analysis History', 'See how your CV improves', 'Phase 2', 'Saved match results, identified gaps, recommendations, and job-specific comparisons.'],
        ];
        abort_unless(isset($modules[$module]), 404);

        return view('workspace.module', ['module' => $module, 'details' => $modules[$module]]);
    }

    public function subscription(Request $request, PlanService $service): View
    {
        return view('workspace.subscription', [
            'currentPlan' => $service->current($request->user()),
            'remaining' => $service->remainingAnalyses($request->user()),
            'plans' => SubscriptionPlan::where('is_active', true)->orderBy('price_cents')->get(),
        ]);
    }
}
