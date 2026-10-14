<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Build your CV · ATS CV</title><link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
    <link rel="preconnect" href="https://fonts.googleapis.com"><link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="builder-body">
<div x-data="cvBuilder({{ Js::from(['draft' => $draft, 'templates' => $templates, 'saveUrl' => route('builder.save'), 'finishUrl' => route('builder.finish'), 'importUrl' => route('builder.import'), 'csrf' => csrf_token(), 'savedAccount' => isset($draft['resume_id'])]) }})" x-cloak>
    <header class="builder-header">
        <a class="builder-logo" href="{{ route('home') }}">ATS CV<span>.</span></a>
        <ol class="builder-progress" aria-label="CV builder progress">
            <template x-for="(label, i) in ['Starting point', 'Your role', 'Template', 'Your CV']" :key="label">
                <li :class="{ active: step === i + 1, complete: step > i + 1 }"><span x-text="step > i + 1 ? '\u2713' : i + 1"></span><small x-text="label"></small></li>
            </template>
        </ol>
        <a class="builder-exit" href="{{ auth()->check() ? route('dashboard') : route('login') }}">{{ auth()->check() ? 'Dashboard' : 'Sign in' }} &#8599;</a>
    </header>
    <main>
        <div class="builder-alert" x-show="error" role="alert" x-text="error"></div>
        @if(session('status'))<div class="builder-success" role="status">{{ session('status') }}</div>@endif
        <section class="builder-question" x-show="step === 1">
            <div class="builder-guide" aria-hidden="true"><span class="guide-shape shape-blue"></span><span class="guide-shape shape-peach"></span><span class="guide-shape shape-green"></span><div class="guide-disc"><svg viewBox="0 0 120 120"><path d="M30 111c0-32 10-45 30-45s30 13 30 45" fill="#346de1"/><path d="M35 53c-3-35 15-41 25-41s31 9 25 44l-7 23H42z" fill="#24344b"/><ellipse cx="60" cy="47" rx="19" ry="25" fill="#e8b794"/><path d="M39 39c5-27 36-30 43 5-9-7-11-14-14-19-5 10-16 15-29 14" fill="#24344b"/><path d="M52 60q8 7 16 0" fill="none" stroke="#a06653" stroke-width="2"/></svg></div><span class="guide-spark">&#10022;</span></div>
            <span class="builder-eyebrow">LET’S START WITH YOUR STORY</span>
            <h1>Do you have an existing CV<br>to use as a starting point?</h1>
            <p>Bring what you have, or build something new. We’ll guide you from here.</p>
            <div class="builder-answers"><button class="builder-btn green" @click="$refs.importPanel.hidden = false; $refs.upload.focus()">Yes, I have a CV <span>&#8599;</span></button><button class="builder-btn purple" @click="go(2)">No, start from scratch <span>&#8599;</span></button></div>
            <div class="builder-import" x-ref="importPanel" hidden>
                <label for="cv-upload">Upload your existing CV</label><p>PDF or DOCX, up to 5 MB. We’ll extract its text so you can review it while filling in your new CV.</p>
                <input id="cv-upload" x-ref="upload" type="file" accept=".pdf,.docx" @change="upload($event)" :disabled="importing">
                <p x-show="importing" role="status">Reading your document…</p><p x-show="importError" x-text="importError" role="alert" class="import-error"></p>
                <button class="builder-text-btn" @click="go(2)">Continue without an upload &#8594;</button>
            </div>
            <small class="builder-privacy">No account needed to start. Sign in when you’re ready to save your CV.</small>
        </section>
        <section class="builder-question" x-show="step === 2">
            <button class="builder-back" @click="go(1)">&#8592; Back</button>
            <div class="role-illustration" aria-hidden="true"><span>&#8599;</span><div class="role-document"><i></i><i></i><i></i><i></i></div><b>YOUR NEXT CHAPTER</b></div>
            <span class="builder-eyebrow">A LITTLE DIRECTION GOES A LONG WAY</span>
            <h1>What position are<br>you applying for?</h1><p>We’ll use this as the headline on your CV. You can change it later.</p>
            <form class="builder-role-form" @submit.prevent="go(3)"><label class="sr-only" for="target-role">Target position</label><input id="target-role" x-model="data.role" maxlength="150" placeholder="e.g. Software Engineer, Marketing Manager" autocomplete="organization-title"><button class="builder-btn green" type="submit" :disabled="!data.role.trim()">Next &#8594;</button></form>
            <button class="builder-text-btn" @click="go(3)">Skip this step</button>
        </section>
        <section class="builder-gallery" x-show="step === 3">
            <button class="builder-back" @click="go(2)">&#8592; Back</button>
            <span class="builder-eyebrow">A GREAT FIRST IMPRESSION STARTS HERE</span>
            <h1>Choose a template for your CV.<br><span>Make it yours.</span></h1><p>20 designs. One career story. You can switch templates at any time without losing your details.</p>
            <div class="builder-filters" role="group" aria-label="Template categories"><template x-for="filter in ['All', 'Modern', 'Simple', 'Traditional', 'Creative']"><button @click="category = filter" :class="{ selected: category === filter }" :aria-pressed="category === filter" x-text="filter"></button></template></div>
            <div class="template-grid"><template x-for="template in visibleTemplates" :key="template.id"><button class="template-card" @click="choose(template)" :aria-label="'Choose ' + template.name + ' template'"><span class="template-recommended" x-show="recommendedIds.includes(template.id)">Suggested for your role</span><div class="template-preview" x-html="preview(template, true)"></div><div class="template-caption"><div><h2 x-text="template.name"></h2><p x-text="template.category + ' · ' + (['double','sidebar'].includes(template.layout) ? 'Two columns' : 'One column')"></p></div><span class="template-arrow">&#8599;</span></div></button></template></div>
        </section>
        <section class="builder-editor" x-show="step === 4">
            <div class="editor-toolbar"><button class="builder-text-btn" @click="go(3)">&#8592; Change template</button><span class="editor-save-state" role="status" x-text="saveState"></span><div class="editor-actions">@if(auth()->user()?->hasVerifiedEmail() && isset($draft['resume_id']))<button class="builder-text-btn" @click="window.print()">Print / PDF</button>@endif<button class="builder-btn green" @click="finish()" :disabled="busy" x-text="busy ? 'Saving…' : '{{ auth()->check() ? 'Save my CV' : 'Done — save my CV' }}'"></button></div></div>
            <div class="editor-intro"><h1>Your story, beautifully presented.</h1><p>Edit the fields to see your CV come to life. Click a section in the preview to edit it.</p></div>
            <div class="editor-layout">
                <aside class="editor-form">
                    <label class="editor-section-label" for="editor-section">Edit section</label><select id="editor-section" x-model="tab"><template x-for="section in sections"><option :value="section.id" x-text="section.label"></option></template></select>
                    <div x-show="tab === 'personal'" class="editor-fields"><h2>Personal details</h2><label>Full name<input x-model="data.name" maxlength="150" placeholder="Your name" autocomplete="name"></label><label>Target role<input x-model="data.role" maxlength="150" placeholder="The role you are applying for"></label><label>Email<input type="email" x-model="data.email" maxlength="255" placeholder="you@example.com" autocomplete="email"></label><label>Phone<input x-model="data.phone" maxlength="80" placeholder="+27…" autocomplete="tel"></label><label>Location<input x-model="data.location" maxlength="150" placeholder="City, country"></label><label>LinkedIn / portfolio<input x-model="data.website" maxlength="255" placeholder="linkedin.com/in/yourname"></label></div>
                    <div x-show="tab === 'summary'" class="editor-fields"><h2>Summary</h2><p>Write a short introduction focused on your strengths and the role you want.</p><label>Professional summary<textarea x-model="data.summary" maxlength="5000" rows="8" placeholder="Your experience, strengths, and the value you bring…"></textarea></label></div>
                    <div x-show="tab === 'skills'" class="editor-fields"><h2>Skills</h2><label>Your skills<textarea x-model="data.skills" maxlength="2000" rows="7" placeholder="Project management, Communication, Excel…"></textarea></label><p>Separate skills with commas or put each on a new line.</p></div>
                    @foreach(['experience' => ['Experience', ['title'=>'Job title','company'=>'Company','period'=>'Date period','location'=>'Location','description'=>'Accomplishments (one per line)']], 'education' => ['Education', ['degree'=>'Degree / qualification','school'=>'School / university','period'=>'Date period','location'=>'Location']], 'achievements' => ['Achievements', ['title'=>'Achievement title','description'=>'Impact / description']], 'courses' => ['Courses', ['title'=>'Course title','institution'=>'Institution / provider']], 'languages' => ['Languages', ['name'=>'Language','level'=>'Proficiency']]] as $section => [$heading, $fields])
                    <div x-show="tab === '{{ $section }}'" class="editor-fields"><h2>{{ $heading }}</h2><template x-for="(item, index) in data.{{ $section }}" :key="index"><div class="editor-entry"><div class="editor-entry-tools"><span x-text="'{{ $heading }} ' + (index + 1)"></span><button @click="move('{{ $section }}', index, -1)" :disabled="index === 0" aria-label="Move up">&#8593;</button><button @click="move('{{ $section }}', index, 1)" :disabled="index === data.{{ $section }}.length - 1" aria-label="Move down">&#8595;</button><button @click="data.{{ $section }}.splice(index, 1)" aria-label="Remove entry">×</button></div>
                    @foreach($fields as $field => $label)<label>{{ $label }}@if($field === 'description')<textarea x-model="item.{{ $field }}" maxlength="{{ $section === 'experience' ? 5000 : 2000 }}" rows="5"></textarea>@elseif($field === 'level')<select x-model="item.level">@foreach(['Native','Fluent','Advanced','Intermediate','Beginner'] as $level)<option>{{ $level }}</option>@endforeach</select>@else<input x-model="item.{{ $field }}" maxlength="{{ in_array($field, ['period','location']) ? 150 : ($field === 'name' ? 100 : 200) }}">@endif</label>@endforeach
                    </div></template><button class="editor-add" @click="add('{{ $section }}')" :disabled="data.{{ $section }}.length >= 20">+ Add {{ strtolower($heading) }}</button></div>
                    @endforeach
                    <details class="imported-reference" x-show="data.imported_text"><summary>Text from your uploaded CV</summary><p>Use this as a reference. Review the text and add it to the appropriate sections.</p><pre x-text="data.imported_text"></pre></details>
                    <div class="editor-note"><span>&#8599;</span><p>Your words. Your experience. Keep your CV accurate and specific.</p></div>
                </aside>
                <div class="editor-preview"><div class="editor-paper-heading"><span x-text="selected.name"></span><small>LIVE PREVIEW</small></div><div class="editor-paper-wrap" @click="const section = $event.target.closest('[data-section]'); if (section) tab = section.dataset.section" x-html="preview(selected)"></div></div>
            </div>
            <p class="builder-bottom-note">{{ auth()->check() ? 'Your account keeps your career story in one place.' : 'When you’re done, sign in or create an account to save your CV. Your draft will carry over.' }}</p>
        </section>
    </main>
</div>
<noscript><p class="builder-alert">Please enable JavaScript to use the CV builder.</p><a href="{{ route('home') }}">Back to home</a></noscript>
</body>
</html>
