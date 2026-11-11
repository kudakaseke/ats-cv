<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CvStructuredResume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvAiRewriteTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['job_description' => 'PHP HTML CSS', 'resume_text' => "Alex Morgan\nSkills\nC#, Python", 'confirmed_keywords' => ['PHP', 'HTML']];
    }

    private function structured(array $extra = []): array
    {
        return array_replace(['name' => 'Alex Morgan', 'role' => '', 'email' => '', 'phone' => '', 'location' => '', 'website' => '', 'summary' => '', 'references' => '', 'skills' => ['C#', 'Python', 'PHP', 'HTML'], 'core_strengths' => [], 'competencies' => [], 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => [], 'custom_sections' => []], $extra);
    }

    private function setupProvider(): void
    {
        config(['services.cv_ai.provider' => 'gemini', 'services.gemini.key' => 'fake-test-key', 'services.gemini.model' => 'test-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_target_advert_cannot_rename_an_accounting_applicant(): void
    {
        $this->setupProvider();
        $experience = [['title' => 'Accounts Clerk', 'company' => 'Example Ltd', 'period' => '2021 - 2024', 'location' => '', 'responsibilities' => ['Prepared invoices.']]];
        $draft = ['structured_resume' => $this->structured(['role' => 'Procurement Officer', 'experience' => $experience]), 'changes' => [], 'unsupported_keywords' => []];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', ['job_description' => 'Procurement Officer', 'resume_text' => "Alex Morgan\nAccountant\nExperience\nAccounts Clerk\nExample Ltd\n2021 - 2024\nPrepared invoices.", 'confirmed_keywords' => []])
            ->assertOk()->assertJsonPath('structured_resume.role', 'Accountant')->assertJsonPath('structured_resume.experience', $experience);
    }

    public function test_word_reference_preserves_grouped_skills_combines_strengths_and_places_referees_last(): void
    {
        $mapper = app(CvStructuredResume::class);
        $data = $mapper->data($this->structured([
            'skills' => ['Programming: Python, PHP', 'Databases: MySQL'],
            'core_strengths' => ['Teamwork', 'Communication'], 'competencies' => ['teamwork', 'Organisation'],
            'references' => "Jane Example|Manager|jane@example.invalid\nSam Example | Lecturer | sam@example.invalid",
            'custom_sections' => [['title' => 'Relevant Work', 'content' => 'Supported colleagues.']],
        ]));
        $this->assertSame("Programming: Python, PHP\nDatabases: MySQL", $data['skills']);
        $this->assertSame('Teamwork, Communication, Organisation', $data['core_strengths']);
        $this->assertSame('', $data['competencies']);
        $text = $mapper->text($data);
        $this->assertStringNotContainsString('Key Competencies', $text);
        $this->assertLessThan(strpos($text, 'References'), strpos($text, 'Competencies'));
        $this->assertLessThan(strpos($text, 'Competencies'), strpos($text, 'Relevant Work'));
        $this->assertStringEndsWith('sam@example.invalid', $text);
    }

    public function test_ai_returns_a_reviewable_draft_with_server_calculated_scores(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(), 'changes' => ['Added confirmed PHP and HTML skills.'], 'unsupported_keywords' => ['CSS']];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', [...$this->payload(), 'score' => 100])->assertOk()->assertJsonPath('before.score', 0)->assertJsonPath('after.score', 29)->assertJsonPath('after.keyword_score', 67)->assertJsonPath('structured_resume.skills', ['C#', 'Python', 'PHP', 'HTML']);
        Http::assertSent(function ($request) {
            $input = json_decode($request['contents'][0]['parts'][0]['text'], true);

            return $request->hasHeader('x-goog-api-key', 'fake-test-key')
                && $input['confirmed_keywords'] === ['PHP', 'HTML']
                && $input['analysis']['method'] === 'tfidf-cosine-v1'
                && $input['analysis']['missing'] === ['PHP', 'HTML', 'CSS']
                && count($input['analysis']['suggestions']) === 3
                && str_contains($request['systemInstruction']['parts'][0]['text'], 'Do not invent experience')
                && $request['generationConfig']['responseMimeType'] === 'application/json';
        });
        $this->assertFalse(session()->has('cv_builder'));
    }

    public function test_user_confirmed_skills_are_preserved_if_provider_omits_them_without_inventing_job_duties(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(['skills' => ['C#', 'Python']]), 'changes' => ['Reorganised existing skills.'], 'unsupported_keywords' => ['PHP', 'CSS']];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', $this->payload())->assertOk()
            ->assertJsonPath('structured_resume.skills', ['C#', 'Python', 'PHP', 'HTML'])
            ->assertJsonPath('after.keyword_score', 67)
            ->assertJsonPath('structured_resume.experience', [])
            ->assertJsonPath('unsupported_keywords', ['CSS']);
    }

    public function test_whole_job_skill_phrases_can_be_added_without_claiming_a_missing_qualification(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(['skills' => ['Python']]), 'changes' => ['Reorganised skills.'], 'unsupported_keywords' => ['Bachelor of Commerce']];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $payload = ['job_description' => "Qualifications\nBachelor of Commerce\nSkills\nIdentifying Sales Opportunities, Oral Communications", 'resume_text' => "Alex Morgan\nEducation\nDiploma in Computing\nSkills\nPython", 'confirmed_keywords' => ['Identifying Sales Opportunities', 'Oral Communications']];
        $this->postJson('/checker/rewrite', $payload)->assertOk()->assertJsonPath('after.keyword_score', 67)
            ->assertJsonPath('structured_resume.skills', ['Python', 'Identifying Sales Opportunities', 'Oral Communications'])
            ->assertJsonPath('structured_resume.education', [])->assertJsonPath('after.missing', ['Bachelor of Commerce']);
        $this->postJson('/checker/rewrite', [...$payload, 'confirmed_keywords' => ['Bachelor of Commerce']])->assertUnprocessable();
    }

    public function test_supported_transferable_job_skills_are_retained_even_when_ai_omits_them(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(['skills' => ['Python']]), 'changes' => [], 'unsupported_keywords' => []];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', ['job_description' => "Skills\nOral communication, Written communication", 'resume_text' => "Alex Example\nExperience\nTrained 12 organisations in budgeting.\nAuthored training manuals for community groups.", 'confirmed_keywords' => []])->assertOk()
            ->assertJsonPath('structured_resume.skills', ['Python'])
            ->assertJsonPath('structured_resume.core_strengths', ['Oral communication', 'Written communication'])
            ->assertJsonPath('after.keyword_score', 100);
    }

    public function test_auth_and_confirmed_keywords_are_checked_before_sending_data(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/checker/rewrite', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->postJson('/checker/rewrite', [...$this->payload(), 'confirmed_keywords' => ['Unrelated skill']])->assertUnprocessable();
        Http::assertNothingSent();
    }

    public function test_missing_configuration_and_provider_failures_preserve_the_cv_and_hide_provider_details(): void
    {
        $this->setupProvider();
        config(['services.gemini.key' => null]);
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503)->assertJsonMissingPath('rewritten_resume');
        Http::assertNothingSent();
        config(['services.gemini.key' => 'fake-test-key']);
        Http::fake(['*' => Http::response(['error' => ['message' => 'Private provider diagnostics']], 429)]);
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503)->assertDontSee('Private provider diagnostics')->assertDontSee('fake-test-key');
    }

    public function test_malformed_or_truncated_ai_output_is_not_applied(): void
    {
        $this->setupProvider();
        Http::fakeSequence()->push(['candidates' => [['finishReason' => 'MAX_TOKENS', 'content' => ['parts' => [['text' => '{']]]]]])
            ->push(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => '{"rewritten_resume":""}']]]]]]);
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503)->assertJsonMissingPath('rewritten_resume');
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503)->assertJsonMissingPath('rewritten_resume');
    }

    public function test_named_fields_reach_the_builder_without_guessing_from_section_words_in_duties(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(['email' => 'alex@example.com', 'summary' => 'Web developer.', 'skills' => ['PHP', 'HTML'], 'experience' => [['title' => 'Web Developer', 'company' => 'Example Studio', 'period' => '2022 - Present', 'location' => 'Harare', 'responsibilities' => ['Skills: trained colleagues in website testing.', 'Education: delivered internal workshops.']]], 'education' => [['degree' => 'Diploma', 'school' => 'Example College', 'period' => '2020 - 2022', 'location' => 'Harare']]]), 'changes' => ['Aligned terminology.'], 'unsupported_keywords' => ['CSS']];
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $response = $this->postJson('/checker/rewrite', $this->payload())->assertOk()->assertJsonPath('structured_resume.experience.0.title', 'Web Developer');
        $this->post('/checker/edit', ['resume_text' => 'Intentionally ambiguous text which must not be parsed', 'structured_resume' => json_encode($response->json('structured_resume')), 'generated' => 1, 'job_description' => 'PHP HTML CSS', 'original_resume_text' => $this->payload()['resume_text']])->assertRedirect(route('builder.start'))
            ->assertSessionHas('cv_builder.data.template', 'tailored-reference')
            ->assertSessionHas('cv_builder.data.section_titles.experience', 'Experience')
            ->assertSessionHas('cv_builder.data.name', 'Alex Morgan')
            ->assertSessionHas('cv_builder.data.summary', 'Web developer.')
            ->assertSessionHas('cv_builder.data.skills', 'PHP, HTML')
            ->assertSessionHas('cv_builder.data.experience.0.company', 'Example Studio')
            ->assertSessionHas('cv_builder.data.experience.0.description', "Skills: trained colleagues in website testing.\nEducation: delivered internal workshops.")
            ->assertSessionHas('cv_builder.data.education.0.period', '2020 - 2022')
            ->assertSessionHas('cv_builder.data.education.0.school', 'Example College');
        // An already-open checker page may not yet submit the new hidden JSON field.
        $this->post('/checker/edit', ['resume_text' => $response->json('rewritten_resume'), 'generated' => 1, 'job_description' => 'PHP HTML CSS', 'original_resume_text' => $this->payload()['resume_text']])
            ->assertRedirect(route('builder.start'))->assertSessionHas('cv_builder.data.experience.0.title', 'Web Developer')
            ->assertSessionHas('cv_builder.data.skills', 'PHP, HTML');
    }

    public function test_long_grouped_skills_round_trip_without_repeated_tools_labels(): void
    {
        $this->setupProvider();
        $tools = 'Tools: '.implode(', ', array_map(fn ($index) => 'Supported software package '.$index, range(1, 12)));
        $draft = ['structured_resume' => $this->structured(['skills' => [$tools]]), 'changes' => [], 'unsupported_keywords' => []];
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $response = $this->postJson('/checker/rewrite', [...$this->payload(), 'confirmed_keywords' => []])->assertOk()
            ->assertJsonPath('structured_resume.skills', [$tools]);
        $this->post('/checker/edit', [
            'resume_text' => $response->json('rewritten_resume'), 'structured_resume' => json_encode($response->json('structured_resume')),
            'generated' => 1, 'job_description' => $this->payload()['job_description'], 'original_resume_text' => $this->payload()['resume_text'],
        ])->assertRedirect(route('builder.start'))->assertSessionHas('cv_builder.data.skills', $tools);
    }

    public function test_flattened_output_is_rejected_instead_of_opening_an_empty_cv(): void
    {
        $this->setupProvider();
        $draft = ['rewritten_resume' => 'Alex Morgan alex@example.com Professional Summary Web developer Skills PHP HTML Education Diploma', 'changes' => [], 'unsupported_keywords' => []];
        Http::fake(['*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503)->assertJsonMissingPath('rewritten_resume');
    }

    public function test_language_schema_accepts_unknown_proficiency_without_invalid_empty_enum_values(): void
    {
        $mapper = app(CvStructuredResume::class);
        $schema = $mapper->schema()['properties']['languages']['items']['properties']['level'];
        $this->assertTrue($schema['nullable']);
        $this->assertNotContains('', $schema['enum']);
        $data = $mapper->data($this->structured(['languages' => [['name' => 'English', 'level' => null]]]));
        $this->assertSame(['name' => 'English', 'level' => ''], $data['languages'][0]);
    }

    public function test_openai_rewrites_named_cv_fields_and_uses_strict_schema_without_storing_the_request(): void
    {
        $this->setupProvider();
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-openai-key', 'services.openai.model' => 'gpt-4.1']);
        $draft = ['structured_resume' => $this->structured(), 'changes' => ['Added confirmed PHP and HTML skills.'], 'unsupported_keywords' => ['CSS']];
        Http::fake(['api.openai.com/v1/responses' => Http::response(['status' => 'completed', 'output' => [
            ['type' => 'reasoning', 'summary' => []],
            ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode($draft)]]],
        ]])]);
        $response = $this->postJson('/checker/rewrite', $this->payload())->assertOk()
            ->assertJsonPath('before.score', 0)->assertJsonPath('after.keyword_score', 67)
            ->assertJsonPath('unsupported_keywords', ['CSS']);
        Http::assertSent(function ($request) {
            $input = json_decode($request['input'][0]['content'], true);
            $schema = $request['text']['format']['schema'];
            $level = $schema['properties']['structured_resume']['properties']['languages']['items']['properties']['level'];

            return $request->hasHeader('Authorization', 'Bearer fake-openai-key')
                && str_contains($request['instructions'], 'single-column professional CV')
                && str_contains($request['instructions'], 'Additional Experience, Technical Skills, Education, Certifications and Skills')
                && str_contains($request['instructions'], 'References last')
                && $request['store'] === false && $request['model'] === 'gpt-4.1'
                && $request['text']['format']['strict'] === true
                && $schema['additionalProperties'] === false
                && $schema['properties']['structured_resume']['additionalProperties'] === false
                && $schema['properties']['structured_resume']['properties']['experience']['items']['additionalProperties'] === false
                && $level['type'] === ['string', 'null'] && in_array(null, $level['enum'], true)
                && $input['original_resume'] === $this->payload()['resume_text']
                && $input['job_description'] === $this->payload()['job_description']
                && str_contains($request['instructions'], 'Do not invent experience');
        });
        $this->post('/checker/edit', [
            'resume_text' => $response->json('rewritten_resume'), 'generated' => 1,
            'original_resume_text' => $this->payload()['resume_text'], 'job_description' => $this->payload()['job_description'],
        ])->assertRedirect(route('builder.start'))->assertSessionHas('cv_builder.data.skills', 'C#, Python, PHP, HTML');
    }

    public function test_openai_incomplete_refused_and_malformed_results_do_not_replace_the_original_cv(): void
    {
        $this->setupProvider();
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => 'fake-openai-key', 'services.openai.model' => 'gpt-4.1']);
        Http::fakeSequence()->push(['status' => 'incomplete', 'output' => []])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'refusal', 'refusal' => 'Unable to comply']]]]])
            ->push(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{']]]]])
            ->push(['error' => ['message' => 'Private diagnostic']], 429);
        for ($i = 0; $i < 4; $i++) {
            $this->withSession(['cv_builder' => ['data' => ['name' => 'Original Person']]])
                ->postJson('/checker/rewrite', $this->payload())->assertStatus(503)
                ->assertJsonMissingPath('rewritten_resume')->assertDontSee('fake-openai-key')->assertDontSee('Private diagnostic')
                ->assertSessionHas('cv_builder.data.name', 'Original Person');
        }
    }

    public function test_missing_openai_key_does_not_silently_send_the_cv_to_another_provider(): void
    {
        $this->setupProvider();
        config(['services.cv_ai.provider' => 'openai', 'services.openai.key' => null]);
        $this->postJson('/checker/rewrite', $this->payload())->assertStatus(503);
        Http::assertNothingSent();
    }

    public function test_certification_dates_survive_structured_rewriting_and_older_drafts_remain_compatible(): void
    {
        $mapper = app(CvStructuredResume::class);
        $data = $mapper->data($this->structured(['courses' => [['title' => 'Existing Certificate', 'institution' => 'Actual Provider', 'period' => 'Jun 2024']]]));
        $this->assertSame('Jun 2024', $data['courses'][0]['period']);
        $this->assertStringContainsString('Jun 2024', $mapper->text($data));
        $legacy = $mapper->data($this->structured(['courses' => [['title' => 'Existing Certificate', 'institution' => 'Actual Provider']]]));
        $this->assertSame('', $legacy['courses'][0]['period']);
    }

    public function test_education_grade_survives_structured_rewriting_without_inventing_missing_grades(): void
    {
        $mapper = app(CvStructuredResume::class);
        $education = ['degree' => 'Bachelor of Commerce', 'school' => 'Example College', 'period' => '2024', 'location' => '', 'grade' => 'First Class'];
        $data = $mapper->data($this->structured(['education' => [$education]]));
        $this->assertSame('First Class', $data['education'][0]['grade']);
        $this->assertStringContainsString('Degree class/grade: First Class', $mapper->text($data));
        unset($education['grade']);
        $legacy = $mapper->data($this->structured(['education' => [$education]]));
        $this->assertSame('', $legacy['education'][0]['grade']);
    }
}
