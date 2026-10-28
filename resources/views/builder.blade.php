<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Build your CV · atscvdetector</title><link rel="icon" href="{{ asset('favicon.png').'?v=minimal-cv-3' }}" type="image/png"><link rel="icon" href="{{ asset('favicon.svg').'?v=minimal-cv-3' }}" type="image/svg+xml" sizes="any">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="builder-body">
<div x-data="cvBuilder({{ Js::from(['authenticated' => auth()->check(), 'continueUrl' => route('builder.continue'), 'draft' => $draft, 'templates' => $templates, 'allowedTemplateIds' => app(\App\Services\PlanService::class)->templateIds(auth()->user()), 'positionGroups' => config('cv_positions'), 'departments' => config('cv_departments'), 'dashboardUrl' => route('dashboard'), 'pricingUrl' => route('pricing'), 'saveUrl' => route('builder.save'), 'finishUrl' => route('builder.finish'), 'importUrl' => route('builder.import'), 'exportUrl' => route('builder.export'),
        'reviewRequested' => (bool) ($draft['review_ready'] ?? false), 'csrf' => csrf_token(), 'savedAccount' => isset($draft['resume_id']), 'resumeId' => $draft['resume_id'] ?? null]) }})" x-cloak>
    <header class="builder-header">
        <x-back-button :href="route('dashboard')" @click.prevent="builderBack()" />
        <button class="builder-template-button" x-show="step === 4" @click="go(3)" :disabled="!!downloading"><x-icon name="layout-template" />Change template</button>

        <a class="builder-exit" href="{{ route('dashboard') }}" @click.prevent="leave($el.href)"><x-icon name="layout-dashboard" />Dashboard</a>
    </header>
    <nav class="builder-progress-row" aria-label="CV creation steps">
        <ol class="builder-progress" aria-label="CV builder progress">
            <template x-for="(label, i) in ['Starting point', 'Your role', 'Template', 'Your CV']" :key="label">
                <li :class="{ active: step === i + 1, complete: step > i + 1 }"><span x-text="step > i + 1 ? '\u2713' : i + 1"></span><small x-text="label"></small></li>
            </template>
        </ol>
    </nav>
    <main>
        <x-input-error :messages="$errors->get('resume_limit')" class="builder-alert" />
        <div class="builder-success builder-add-success" x-show="successMessage" x-text="successMessage" role="status" aria-live="polite" aria-atomic="true"></div>
        <div class="builder-alert" x-show="error" role="alert" x-text="error"></div>
        @if(session('status'))<div class="builder-success" role="status">{{ session('status') }}</div>@endif
        <section class="builder-question builder-question-compact" x-show="step === 1">
            <div class="builder-guide" aria-hidden="true"><span class="guide-shape shape-blue"></span><span class="guide-shape shape-peach"></span><span class="guide-shape shape-ice"></span><div class="guide-disc"><svg viewBox="0 0 120 120"><path d="M30 111c0-32 10-45 30-45s30 13 30 45" fill="#346de1"/><path d="M35 53c-3-35 15-41 25-41s31 9 25 44l-7 23H42z" fill="#24344b"/><ellipse cx="60" cy="47" rx="19" ry="25" fill="#e8b794"/><path d="M39 39c5-27 36-30 43 5-9-7-11-14-14-19-5 10-16 15-29 14" fill="#24344b"/><path d="M52 60q8 7 16 0" fill="none" stroke="#a06653" stroke-width="2"/></svg></div><span class="guide-spark">&#10022;</span></div>
            <span class="builder-eyebrow">LET’S START WITH YOUR STORY</span>
            <h1>Do you have an existing CV<br>to use as a starting point?</h1>
            <p>Bring what you have, or build something new. We’ll guide you from here.</p>
            <div class="builder-answers"><button class="builder-btn primary" @click="go(5)">Yes, I have a CV </button><button class="builder-btn secondary" @click="go(2)">No, start from scratch </button></div>
            <small class="builder-privacy">Start without an account. Sign in when you're ready to save.</small>
        </section>
        <section class="builder-question" x-show="step === 5">
            <h1>Upload your CV or resume</h1>
            <p>We?ll transfer your summary, experience, education, skills and other details into your chosen template.</p>
            <div class="builder-import">
                <label for="cv-upload">Choose a PDF or Word document</label><p>PDF or DOCX, maximum 2 MB. Review the imported details for accuracy.</p>
                <input id="cv-upload" x-ref="upload" type="file" accept=".pdf,.docx" @change="upload($event)" :disabled="importing">
                <p x-show="importing" role="status">Reading your document?</p><p x-show="importError" x-text="importError" role="alert" class="import-error"></p>
            </div>
        </section>
        <section class="builder-question builder-question-compact" x-show="step === 2">
            <div class="role-illustration" aria-hidden="true"><div class="role-document"><i></i><i></i><i></i><i></i></div><b>YOUR NEXT CHAPTER</b></div>
            <span class="builder-eyebrow">A LITTLE DIRECTION GOES A LONG WAY</span>
            <h1>Please tell me your position<br>so I can recommend templates.</h1><p>We’ll use this as the headline on your CV. You can change it later.</p>
            <form class="builder-role-form" @submit.prevent="goToPositionTemplates()"><label class="sr-only" for="target-role">Target position</label><input id="target-role" x-model="data.role" maxlength="150" placeholder="e.g. Software Engineer, Marketing Manager" autocomplete="organization-title"><button class="builder-btn primary" type="submit" :disabled="!data.role.trim()">Next</button></form>
            <button class="builder-text-btn" @click="go(3)">Skip this step</button>
        </section>
        <section class="builder-gallery" x-show="step === 3">
            <span class="builder-eyebrow">A GREAT FIRST IMPRESSION STARTS HERE</span>
            <h1>Choose a template for your CV.<br><span>Make it yours.</span></h1><p>{{ count($templates) }} designs. One career story. You can switch templates at any time without losing your details.</p>
            @if(!auth()->check() || app(\App\Services\PlanService::class)->current(auth()->user())->slug === 'free')
                <p>Free includes {{ count(config('cv_access.free_templates')) }} templates. <a href="{{ route('pricing') }}">Upgrade to unlock all {{ count($templates) }} designs.</a></p>
            @endif
            <div class="builder-filters" x-ref="templateFilters" role="group" aria-label="Template categories"><template x-for="filter in ['All', ...templateCategories, 'By Department']"><button @click="selectCategory(filter)" :class="{ selected: category === filter }" :aria-pressed="category === filter" x-text="filter"></button></template></div>
            <section class="position-gallery department-gallery" x-show="category === 'By Department'" aria-labelledby="departments-heading">
                <h2 id="departments-heading" class="template-group-heading">By Department <small x-text="departmentExamples.length + ' templates'"></small></h2>
                <p class="position-gallery-intro">Choose your department, select a role, and use its template.</p>
                <div class="template-grid">
                    <template x-for="department in departmentExamples" :key="department.id">
                        <article class="template-card department-card">
                            <button type="button" class="department-preview" @click="chooseDepartment(department)" :aria-label="'Preview and choose ' + department.title + ' template'"><span class="template-preview cv-stage" x-html="departmentPreview(department)"></span></button>
                            <div class="template-caption"><h2 x-text="department.title"></h2><span class="template-lock" x-show="!allowedTemplateIds.includes(department.template.id)" aria-label="Paid plan template"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Paid plan</span></div>
                            <label class="department-role-label" :for="'department-role-' + department.id">Role</label>
                            <select class="department-role-select" :id="'department-role-' + department.id" :value="departmentRoles[department.id] || ''" @change="departmentRoles[department.id] = $event.target.value">
                                <option value="">Choose a role</option><template x-for="role in department.roles" :key="role"><option :value="role" x-text="role"></option></template>
                            </select>
                            <details class="department-role-list"><summary x-text="'View all ' + department.roles.length + ' roles'"></summary><ul><template x-for="role in department.roles" :key="role"><li x-text="role"></li></template></ul></details>
                            <button type="button" class="position-use-template" @click="chooseDepartment(department)" :aria-label="'Use ' + department.title + ' template'">Use This Template</button>
                        </article>
                    </template>
                </div>
            </section>
            <template x-for="group in templateGroups" :key="group.category"><section class="template-group" :aria-label="group.category + ' templates'"><h2 class="template-group-heading"><span x-text="group.category"></span> <small x-text="group.templates.length + ' templates'"></small></h2><div class="template-grid"><template x-for="template in group.templates" :key="template.id"><button class="template-card" :class="{ 'template-is-locked': !allowedTemplateIds.includes(template.id) }" @click="choose(template)" :aria-label="'Choose ' + template.name + ' template'"><span class="template-lock" x-show="!allowedTemplateIds.includes(template.id)" aria-label="Paid plan template"><svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="10" width="14" height="11" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/></svg>Paid plan</span><span class="template-recommended" x-show="recommendedIds.includes(template.id)">Suggested for your role</span><div class="template-preview cv-stage" x-html="preview(template, true)"></div><div class="template-caption"><div><h2 x-text="template.name"></h2><p x-text="template.category + ' · ' + (allowedTemplateIds.includes(template.id) ? 'Included' : 'Paid plan')"></p></div></div></button></template></div></section></template>
        </section>
        <section class="builder-editor" x-show="step === 4" :class="{ 'is-expanded': editorExpanded, 'is-reviewing': reviewing }">
            <div class="editor-toolbar">
                <span class="editor-save-state" role="status" x-text="saveState"></span>
                <div class="editor-actions">
                    <div class="canvas-section-tools" x-show="!reviewing">
                        <select x-model="addSectionId" aria-label="Section to add"><template x-for="section in sections.filter(s => !['personal', 'summary', 'experience', 'education', 'skills'].includes(s.id))" :key="section.id"><option :value="section.id" x-text="section.label"></option></template></select>
                        <button type="button" class="builder-text-btn" @click="showInlineSection()">+ Add section</button>
                    </div>
                    <button class="builder-text-btn" x-show="reviewing" @click="editCv()" :disabled="!!downloading"><x-icon name="pencil" />Edit CV</button>
                    <div class="download-picker" @click.outside="downloadMenu = false" @keydown.escape.stop="downloadMenu = false; $refs.downloadButton.focus()">
                        <button class="builder-btn secondary" x-ref="downloadButton" @click="openDownloads()" :disabled="!!downloading" :aria-expanded="downloadMenu" aria-controls="cv-download-menu"><x-icon name="download" /><span x-text="downloading ? 'Preparing download...' : 'Download'"></span><x-icon name="chevron-down" /></button>
                        <div id="cv-download-menu" class="download-menu" x-show="downloadMenu" role="group" aria-label="Download format">
                            <button x-ref="pdfDownload" @click="download('pdf')" :disabled="!!downloading"><x-download-format-icon format="pdf" /><span><strong>PDF</strong><small>Ready to send</small></span></button>
                            <button @click="download('word')" :disabled="!!downloading"><x-download-format-icon format="word" /><span><strong>Word (.docx)</strong><small>Editable document</small></span></button>
                        </div>
                    </div>
                    <button class="builder-btn primary" x-show="!reviewing" @click="startReview()"><x-icon name="eye" />Review CV</button>
                    <button class="builder-btn primary" x-show="reviewing" @click="finish()" :disabled="busy || !!downloading" x-text="busy ? 'Saving...' : 'Save my CV'"></button>
                </div>
            </div>
            @if(isset($draft['checker_result']))
                @php($comparison = $draft['checker_result'])
                <section class="checker-comparison" aria-label="CV improvement results">
                    <p class="checker-generated-score" role="status">Your tailored CV is ready. Compare each score with its original below.</p>
                    <x-score-pair :wording="$comparison['keyword_after'] ?? null" :wording-before="$comparison['keyword_before'] ?? null" :compatibility="$comparison['compatibility_after'] ?? null" :compatibility-before="$comparison['compatibility_before'] ?? null" />
                    @if(isset($comparison['assessment']))<x-ai-assessment :assessment="$comparison['assessment']" :revised="true" />@endif
                    @if(!empty($comparison['green_flags']))<div class="checker-green-flags"><h3>Qualification strengths</h3><ul>@foreach($comparison['green_flags'] as $flag)<li>{{ $flag }}</li>@endforeach</ul>@if($comparison['certificate_bonus'] ?? 0)<p>Includes {{ $comparison['certificate_bonus'] }} additional certificate bonus points.</p>@endif</div>@endif
                    @if(!empty($comparison['added_keywords']))<details><summary>Requirements added</summary><ul>@foreach($comparison['added_keywords'] as $keyword)<li>{{ $keyword }}</li>@endforeach</ul></details>@endif
                    @if(!empty($comparison['ai_changes']))
                        <details class="ai-change-report" open><summary>What AI changed</summary>
                            <p>Changes made to the generated CV, checked against your original information.</p>
                            <ol>@foreach($comparison['ai_changes'] as $change)<li>
                                <h3>{{ $change['section'] }} — {{ str_replace('_', ' ', $change['change_type']) }}</h3>
                                @if($change['original_text'] !== '')<p><strong>Before:</strong> {{ $change['original_text'] }}</p>@endif
                                @if($change['revised_text'] !== '')<p><strong>After:</strong> {{ $change['revised_text'] }}</p>@endif
                                <p>{{ $change['explanation'] }}</p>
                                @if($change['supporting_evidence'] !== '')<p><strong>Source evidence:</strong> {{ $change['supporting_evidence'] }}</p>@endif
                                @if($change['related_requirement'] !== '')<p><strong>Job requirement:</strong> {{ $change['related_requirement'] }}</p>@endif
                            </li>@endforeach</ol>
                        </details>
                    @elseif(!empty($comparison['changes']))<details><summary>What AI changed</summary><ul>@foreach($comparison['changes'] as $change)<li>{{ $change }}</li>@endforeach</ul></details>@endif
                    @if(array_key_exists('keyword_coverage_percentage', $comparison))
                        <p class="verified-keyword-coverage"><strong>Verified keyword coverage:</strong> {{ $comparison['keyword_coverage_percentage'] === null ? 'No fully supported requirements to measure' : $comparison['keyword_coverage_percentage'].'%' }}. This measures the representation of supported requirements; it does not establish missing qualifications or experience.</p>
                    @endif
                    @if(!empty($comparison['remaining_keywords']) || !empty($comparison['warnings']))
                        <details class="missing-requirements"><summary>Requirements still missing ({{ count($comparison['remaining_keywords'] ?? []) }})</summary>
                            @if(!empty($comparison['warnings']))<section class="checker-review-warnings" role="note"><h3>Warnings</h3><ul>@foreach($comparison['warnings'] as $warning)<li>{{ $warning }}</li>@endforeach</ul></section>@endif
                            @if(!empty($comparison['remaining_groups']))
                                <div class="missing-requirement-groups">@foreach($comparison['remaining_groups'] as $category => $requirements)@if($requirements)<section><h3>{{ ['qualifications' => 'Qualifications', 'skills' => 'Skills', 'competencies' => 'Competencies'][$category] ?? ucfirst($category) }}</h3><ul>@foreach($requirements as $requirement)<li>{{ $requirement }}</li>@endforeach</ul></section>@endif @endforeach</div>
                            @else<ul>@foreach($comparison['remaining_keywords'] ?? [] as $requirement)<li>{{ $requirement }}</li>@endforeach</ul>@endif
                            <p>Add these only where you have supporting skills or experience.</p>
                        </details>
                    @endif
                    @if(isset($comparison['original_text'], $comparison['revised_text']))<details><summary>Compare original and tailored text</summary><div class="checker-text-comparison"><div><h3>Original CV</h3><pre>{{ $comparison['original_text'] }}</pre></div><div><h3>Tailored CV</h3><pre>{{ $comparison['revised_text'] }}</pre></div></div></details>@endif
                </section>
            @endif
            <div class="editor-intro"><h1 x-text="reviewing ? 'Review your CV.' : 'Make your CV yours.'"></h1><p x-text="reviewing ? 'This preview shows the page layout used for your PDF download. Check your details before downloading. Word may flow differently in your document app.' : 'Click any text on your CV to edit it. Add entries directly in each section, then select Review CV.'"></p></div>
            <div class="editor-layout">
                <div class="editor-preview" >
                    <div class="editor-paper-heading">
                        <div class="review-zoom" x-show="reviewing" role="group" aria-label="CV preview zoom">
                            <button type="button" @click="setReviewZoom(reviewZoom - 10)" :disabled="reviewZoom <= 25" aria-label="Zoom out">&minus;</button>
                            <input type="range" min="25" max="200" step="1" :value="reviewZoom" @input="setReviewZoom($event.target.value)" aria-label="CV zoom percentage">
                            <button type="button" @click="setReviewZoom(reviewZoom + 10)" :disabled="reviewZoom >= 200" aria-label="Zoom in">+</button>
                            <output x-text="reviewZoom + '%'" aria-live="polite"></output>
                        </div>
                        <small x-show="!reviewing">CLICK TEXT TO EDIT</small>
                    </div>
                    <div class="review-viewport"><div class="editor-paper-wrap cv-stage" :style="{ width: (pageDimensions().designWidth * reviewZoom / 100) + 'px', maxWidth: 'none' }" @change="changeInlineDate($event)" @click="if ($event.target.matches('input[type=month]')) openMonthPicker($event); if ($event.detail === 0) canvasAction($event)" @focusout="commitInline($event)" @mousedown="canvasAction($event)" @paste="pasteInline($event)" x-html="reviewing ? reviewPreview(selected) : editablePreview(selected)"></div></div>
                </div>
            </div>
            <div class="canvas-review-action" x-show="!reviewing">
                <button type="button" class="builder-btn primary" @click="startReview()"><x-icon name="eye" />Review CV</button>
            </div>
            <p class="builder-bottom-note">{{ auth()->check() ? 'Your account keeps your career story in one place.' : 'When you’re done, sign in or create an account to save your CV. Your draft will carry over.' }}</p>
        </section>
    </main>
</div>
<noscript><p class="builder-alert">Please enable JavaScript to use the CV builder.</p><a href="{{ route('home') }}">Back to home</a></noscript>
    <x-cookie-notice />
</body>
</html>
