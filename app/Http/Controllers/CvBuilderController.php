<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use App\Models\User;
use App\Services\CvDocumentExporter;
use App\Services\CvPdfExtractor;
use App\Services\CvProfessionalTitle;
use App\Services\CvReviewComparison;
use App\Services\CvTextParser;
use App\Services\PlanService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CvBuilderController extends Controller
{
    public function show(Request $request)
    {
        if ($request->boolean('new')) {
            $request->session()->forget('cv_builder');

            return redirect()->route('builder.start');
        }

        $draft = $request->session()->get('cv_builder', []);
        if (isset($draft['checker_result']['original_text'], $draft['data']) && empty($draft['professional_title_checked'])) {
            $original = $draft['checker_result']['original_text'];
            $oldTitle = $draft['data']['role'] ?? '';
            $newTitle = app(CvProfessionalTitle::class)->preserve($original, $oldTitle);
            // Repair legacy AI headlines only while they still match the generated
            // snapshot. A title subsequently edited by the user is their choice.
            $snapshot = $draft['checker_result']['revised_text'] ?? '';
            if ($oldTitle !== '' && $oldTitle !== $newTitle && in_array($oldTitle, preg_split('/\R/u', $snapshot), true)) {
                $draft['data']['role'] = $newTitle;
                $draft['checker_result']['revised_text'] = implode("\n", array_map(fn ($line) => $line === $oldTitle ? $newTitle : $line, preg_split('/\R/u', $snapshot)));
                unset($draft['checker_result']['comparison_version']);
            }
            $draft['professional_title_checked'] = true;
        }
        if (isset($draft['checker_result'])) {
            $draft['checker_result'] = app(CvReviewComparison::class)->refreshForUser($draft['checker_result'], $request->user()?->id);
            $request->session()->put('cv_builder', $draft);
        }
        if ($request->boolean('questions')) {
            $draft['step'] = 1;
        }
        if ($request->boolean('templates')) {
            $draft['step'] = 3;
        }

        if (isset($draft['data'])) {
            $draft['data'] = $this->restoreCoreStrengths($draft['data']);
        }

        return view('builder', ['draft' => $draft, 'templates' => config('cv_templates')]);
    }

    public function edit(Request $request, Resume $resume)
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        $draft = ['step' => 4, 'data' => $this->restoreCoreStrengths($resume->content ?? []), 'resume_id' => $resume->id];
        $request->session()->put('cv_builder', $draft);

        return view('builder', ['draft' => $draft, 'templates' => config('cv_templates')]);
    }

    public function destroy(Request $request, Resume $resume)
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        $resume->delete();
        if ($request->session()->get('cv_builder.resume_id') === $resume->id) {
            $request->session()->forget('cv_builder');
        }

        return to_route('workspace.module', 'resumes')->with('status', 'CV deleted.');
    }

    private function restoreCoreStrengths(array $data): array
    {
        if (($data['template'] ?? null) === 'slate') {
            $data['template'] = 'elegant';
        }
        if (! array_key_exists('core_strengths', $data) && ! empty($data['imported_text'])) {
            $parsed = app(CvTextParser::class)->parse($data['imported_text']);
            if (! empty($parsed['core_strengths'])) {
                $data['core_strengths'] = $parsed['core_strengths'];
                if (trim($data['competencies'] ?? '') === trim($parsed['core_strengths'])) {
                    $data['competencies'] = '';
                }
            }
        }

        return $data;
    }

    private function draftRules(): array
    {
        return [
            'step' => ['required', 'integer', 'between:1,5'],
            'resume_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'data' => ['required', 'array:template,role,name,email,phone,location,website,summary,skills,experience,education,achievements,courses,languages,imported_text,competencies,core_strengths,references,custom_sections,hidden_sections,section_positions,section_titles'],
            'data.section_titles' => ['sometimes', 'array:summary,experience,education,skills,achievements,courses,languages,competencies,core_strengths,references'],
            'data.section_titles.*' => ['required', 'string', 'max:100'],
            'data.hidden_sections' => ['sometimes', 'array', 'max:12'],
            'data.hidden_sections.*' => ['string', Rule::in(['summary', 'experience', 'education', 'skills', 'achievements', 'courses', 'languages', 'competencies', 'core_strengths', 'references', 'custom_sections'])],
            'data.section_positions' => ['sometimes', 'array', 'max:12'],
            'data.section_positions.*' => ['array:section,region,after'],
            'data.section_positions.*.section' => ['required', Rule::in(['summary', 'experience', 'education', 'skills', 'achievements', 'courses', 'languages', 'competencies', 'core_strengths', 'references', 'custom_sections'])],
            'data.section_positions.*.region' => ['required', Rule::in(['cv-main', 'cv-side', 'cv-lead', 'cv-footer', 'ats-content'])],
            'data.section_positions.*.after' => ['nullable', Rule::in(['summary', 'experience', 'education', 'skills', 'achievements', 'courses', 'languages', 'competencies', 'core_strengths', 'references', 'custom_sections'])],
            'data.template' => ['required', Rule::in(array_column(config('cv_templates'), 'id'))],
            'data.role' => ['nullable', 'string', 'max:150'],
            'data.name' => ['nullable', 'string', 'max:150'],
            'data.email' => ['nullable', 'email', 'max:255'],
            'data.phone' => ['nullable', 'string', 'max:80'],
            'data.location' => ['nullable', 'string', 'max:150'],
            'data.website' => ['nullable', 'string', 'max:255'],
            'data.summary' => ['nullable', 'string', 'max:5000'],
            'data.skills' => ['nullable', 'string', 'max:2000'],
            'data.core_strengths' => ['nullable', 'string', 'max:5000'],
            'data.competencies' => ['nullable', 'string', 'max:5000'],
            'data.references' => ['nullable', 'string', 'max:5000'],
            'data.custom_sections' => ['sometimes', 'array', 'max:20'],
            'data.custom_sections.*' => ['array:title,content'],
            'data.custom_sections.*.title' => ['required', 'string', 'max:200'],
            'data.custom_sections.*.content' => ['nullable', 'string', 'max:5000'],
            'data.imported_text' => ['nullable', 'string', 'max:20000'],
            'data.experience' => ['present', 'array', 'max:20'],
            'data.experience.*' => ['array:title,company,period,location,description'],
            'data.experience.*.title' => ['nullable', 'string', 'max:200'],
            'data.experience.*.company' => ['nullable', 'string', 'max:200'],
            'data.experience.*.period' => ['nullable', 'string', 'max:150'],
            'data.experience.*.location' => ['nullable', 'string', 'max:150'],
            'data.experience.*.description' => ['nullable', 'string', 'max:5000'],
            'data.education' => ['present', 'array', 'max:20'],
            'data.education.*' => ['array:degree,school,period,location,grade'],
            'data.education.*.grade' => ['sometimes', 'nullable', 'string', 'max:200'],
            'data.education.*.degree' => ['nullable', 'string', 'max:200'],
            'data.education.*.school' => ['nullable', 'string', 'max:200'],
            'data.education.*.period' => ['nullable', 'string', 'max:150'],
            'data.education.*.location' => ['nullable', 'string', 'max:150'],
            'data.achievements' => ['present', 'array', 'max:20'],
            'data.achievements.*' => ['array:title,description'],
            'data.achievements.*.title' => ['nullable', 'string', 'max:200'],
            'data.achievements.*.description' => ['nullable', 'string', 'max:2000'],
            'data.courses' => ['present', 'array', 'max:20'],
            'data.courses.*' => ['array:title,institution,period'],
            'data.courses.*.title' => ['nullable', 'string', 'max:200'],
            'data.courses.*.institution' => ['nullable', 'string', 'max:200'],
            'data.courses.*.period' => ['nullable', 'string', 'max:150'],
            'data.languages' => ['present', 'array', 'max:20'],
            'data.languages.*' => ['array:name,level'],
            'data.languages.*.name' => ['nullable', 'string', 'max:100'],
            'data.languages.*.level' => ['nullable', Rule::in(['Native', 'Fluent', 'Advanced', 'Intermediate', 'Beginner'])],
        ];
    }

    public function export(Request $request, CvDocumentExporter $exporter)
    {
        $rules = $this->draftRules();
        $rules['format'] = ['required', Rule::in(['pdf', 'word'])];
        $rules['data.name'] = ['required', 'string', 'max:150'];
        $validated = $request->validate($rules);
        try {
            $path = $exporter->create($validated['data'], $validated['format']);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Your download could not be prepared. Please try again.'], 503);
        }
        $extension = $validated['format'] === 'pdf' ? 'pdf' : 'docx';
        $type = $validated['format'] === 'pdf' ? 'application/pdf' : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
        $name = Str::slug($validated['data']['name']).'-cv.'.$extension;

        return response()->download($path, $name, ['Content-Type' => $type, 'Cache-Control' => 'private, no-store'])->deleteFileAfterSend(true);
    }

    public function save(Request $request)
    {
        $validated = $request->validate($this->draftRules());
        $previous = $request->session()->get('cv_builder', []);
        $resumeId = array_key_exists('resume_id', $validated) ? $validated['resume_id'] : ($previous['resume_id'] ?? null);
        if ($resumeId !== null) {
            abort_unless($request->user()?->hasVerifiedEmail(), 403);
            $resume = $request->user()->resumes()->findOrFail($resumeId);
            $resume->update(['name' => ($validated['data']['name'] ?? 'Untitled').' CV', 'content' => $validated['data']]);
            $validated['resume_id'] = $resume->id;
        }
        $request->session()->put('cv_builder', $validated);

        return response()->json(['saved' => true]);
    }

    public function finish(Request $request)
    {
        $draft = $request->session()->get('cv_builder');
        if (! $draft || empty($draft['data']['name'])) {
            return redirect()->route('builder.start');
        }
        if (isset($draft['resume_id'])) {
            $resume = $request->user()->resumes()->findOrFail($draft['resume_id']);
        } else {
            $resume = DB::transaction(function () use ($request, $draft) {
                $user = User::whereKey($request->user()->id)->lockForUpdate()->firstOrFail();
                $limit = app(PlanService::class)->current($user)->resume_limit;
                if ($limit !== null && $user->resumes()->count() >= $limit) {
                    throw ValidationException::withMessages(['resume_limit' => 'Your plan allows '.$limit.' saved CV(s). Your draft is preserved. Edit an existing CV or check your subscription.']);
                }

                return $user->resumes()->create(['name' => $draft['data']['name'].' CV', 'source' => 'created', 'status' => 'draft', 'content' => $draft['data']]);
            });
            $draft['resume_id'] = $resume->id;
            $request->session()->put('cv_builder', $draft);
        }

        return redirect()->route('builder.edit', $resume)->with('status', 'Your CV has been saved to your account.');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:application/pdf,application/zip,application/x-zip-compressed,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extensions:pdf,docx', 'max:2048']]);
        $file = $request->file('file');
        $temporaryArchive = null;
        try {
            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                $text = app(CvPdfExtractor::class)->extract($file->getRealPath())['text'];
            } else {
                $temporaryArchive = tempnam(sys_get_temp_dir(), 'ats-cv-');
                $zipPath = $temporaryArchive.'.zip';
                rename($temporaryArchive, $zipPath);
                $temporaryArchive = $zipPath;
                copy($file->getRealPath(), $temporaryArchive);
                $archive = new \PharData($temporaryArchive, 0, null, \Phar::ZIP);
                if (! isset($archive['word/document.xml']) || $archive['word/document.xml']->getSize() > 2000000) {
                    throw new \RuntimeException('Invalid document');
                }
                $xml = $archive['word/document.xml']->getContent();
                if (stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
                    throw new \RuntimeException('Invalid XML');
                }
                if (! mb_check_encoding($xml, 'UTF-8')) {
                    throw new \RuntimeException('Invalid document encoding');
                }
                $previousErrors = libxml_use_internal_errors(true);
                try {
                    $document = new \DOMDocument;
                    if (! $document->loadXML($xml, LIBXML_NONET) || $document->documentElement?->localName !== 'document') {
                        throw new \RuntimeException('Invalid document XML');
                    }
                } finally {
                    libxml_clear_errors();
                    libxml_use_internal_errors($previousErrors);
                }
                $xml = preg_replace('/<w:tab\b[^>]*\/>|<\/w:tc>/', ' ', $xml);
                $text = html_entity_decode(strip_tags(preg_replace('/<\/w:p>|<\/w:tr>|<w:br\b[^>]*\/>/', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'This document could not be read. Try a text-based PDF or DOCX, or start from scratch.']);
        } finally {
            unset($archive);
            if ($temporaryArchive && is_file($temporaryArchive)) {
                unlink($temporaryArchive);
            }
        }
        if (mb_strlen($text) > 20000) {
            throw ValidationException::withMessages(['file' => 'This document contains more than 20,000 characters. Please upload a shorter CV so none of your information is silently omitted.']);
        }
        $text = trim($text);
        if ($text === '') {
            throw ValidationException::withMessages(['file' => 'No text was found. Scanned PDFs need text recognition; please start from scratch.']);
        }
        $data = app(CvTextParser::class)->parse($text);

        return response()->json(['text' => $text, 'email' => $data['email'], 'data' => $data]);
    }
}
