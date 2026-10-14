<?php

namespace App\Http\Controllers;

use App\Models\Resume;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;

class CvBuilderController extends Controller
{
    public function show(Request $request)
    {
        if ($request->boolean('new')) {
            $request->session()->forget('cv_builder');
        }

        return view('builder', ['draft' => $request->session()->get('cv_builder', []), 'templates' => config('cv_templates')]);
    }

    public function edit(Request $request, Resume $resume)
    {
        abort_unless($resume->user_id === $request->user()->id, 404);
        $draft = ['step' => 4, 'data' => $resume->content, 'resume_id' => $resume->id];
        $request->session()->put('cv_builder', $draft);

        return view('builder', ['draft' => $draft, 'templates' => config('cv_templates')]);
    }

    public function save(Request $request)
    {
        $validated = $request->validate([
            'step' => ['required', 'integer', 'between:1,4'],
            'data' => ['required', 'array:template,role,name,email,phone,location,website,summary,skills,experience,education,achievements,courses,languages,imported_text'],
            'data.template' => ['required', Rule::in(array_column(config('cv_templates'), 'id'))],
            'data.role' => ['nullable', 'string', 'max:150'],
            'data.name' => ['nullable', 'string', 'max:150'],
            'data.email' => ['nullable', 'email', 'max:255'],
            'data.phone' => ['nullable', 'string', 'max:80'],
            'data.location' => ['nullable', 'string', 'max:150'],
            'data.website' => ['nullable', 'string', 'max:255'],
            'data.summary' => ['nullable', 'string', 'max:5000'],
            'data.skills' => ['nullable', 'string', 'max:2000'],
            'data.imported_text' => ['nullable', 'string', 'max:20000'],
            'data.experience' => ['present', 'array', 'max:20'],
            'data.experience.*' => ['array:title,company,period,location,description'],
            'data.experience.*.title' => ['nullable', 'string', 'max:200'],
            'data.experience.*.company' => ['nullable', 'string', 'max:200'],
            'data.experience.*.period' => ['nullable', 'string', 'max:150'],
            'data.experience.*.location' => ['nullable', 'string', 'max:150'],
            'data.experience.*.description' => ['nullable', 'string', 'max:5000'],
            'data.education' => ['present', 'array', 'max:20'],
            'data.education.*' => ['array:degree,school,period,location'],
            'data.education.*.degree' => ['nullable', 'string', 'max:200'],
            'data.education.*.school' => ['nullable', 'string', 'max:200'],
            'data.education.*.period' => ['nullable', 'string', 'max:150'],
            'data.education.*.location' => ['nullable', 'string', 'max:150'],
            'data.achievements' => ['present', 'array', 'max:20'],
            'data.achievements.*' => ['array:title,description'],
            'data.achievements.*.title' => ['nullable', 'string', 'max:200'],
            'data.achievements.*.description' => ['nullable', 'string', 'max:2000'],
            'data.courses' => ['present', 'array', 'max:20'],
            'data.courses.*' => ['array:title,institution'],
            'data.courses.*.title' => ['nullable', 'string', 'max:200'],
            'data.courses.*.institution' => ['nullable', 'string', 'max:200'],
            'data.languages' => ['present', 'array', 'max:20'],
            'data.languages.*' => ['array:name,level'],
            'data.languages.*.name' => ['nullable', 'string', 'max:100'],
            'data.languages.*.level' => ['nullable', Rule::in(['Native', 'Fluent', 'Advanced', 'Intermediate', 'Beginner'])],
        ]);
        $previous = $request->session()->get('cv_builder', []);
        if (isset($previous['resume_id'])) {
            abort_unless($request->user()?->hasVerifiedEmail(), 403);
            $resume = $request->user()->resumes()->findOrFail($previous['resume_id']);
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
            $resume = DB::transaction(fn () => $request->user()->resumes()->create([
                'name' => $draft['data']['name'].' CV', 'source' => 'created', 'status' => 'draft', 'content' => $draft['data'],
            ]));
            $draft['resume_id'] = $resume->id;
            $request->session()->put('cv_builder', $draft);
        }

        return redirect()->route('builder.edit', $resume)->with('status', 'Your CV has been saved to your account.');
    }

    public function import(Request $request)
    {
        $request->validate(['file' => ['required', 'file', 'mimetypes:application/pdf,application/zip,application/x-zip-compressed,application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'extensions:pdf,docx', 'max:5120']]);
        $file = $request->file('file');
        $temporaryArchive = null;
        try {
            if (strtolower($file->getClientOriginalExtension()) === 'pdf') {
                $config = new Config;
                $config->setDecodeMemoryLimit(16 * 1024 * 1024);
                $config->setMaxNestingDepth(50);
                $parser = new Parser([], $config);
                $text = $parser->parseFile($file->getRealPath())->getText();
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
                $text = html_entity_decode(strip_tags(preg_replace('/<\/w:p>|<w:br\s*\/>/', "\n", $xml)), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => 'This document could not be read. Try a text-based PDF or DOCX, or start from scratch.']);
        } finally {
            unset($archive);
            if ($temporaryArchive && is_file($temporaryArchive)) {
                unlink($temporaryArchive);
            }
        }
        $text = trim(mb_substr($text, 0, 20000));
        if ($text === '') {
            throw ValidationException::withMessages(['file' => 'No text was found. Scanned PDFs need text recognition; please start from scratch.']);
        }
        preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $text, $email);

        return response()->json(['text' => $text, 'email' => $email[0] ?? '']);
    }
}
