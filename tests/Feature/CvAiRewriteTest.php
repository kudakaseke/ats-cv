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
        return ['job_description' => 'PHP HTML CSS', 'resume_text' => "Alex Morgan\nSkills\nC#, Python", 'confirmed_keywords' => ['PHP', 'HTML'], 'ai_consent' => true];
    }

    private function structured(array $extra = []): array
    {
        return array_replace(['name' => 'Alex Morgan', 'role' => '', 'email' => '', 'phone' => '', 'location' => '', 'website' => '', 'summary' => '', 'references' => '', 'skills' => ['C#', 'Python', 'PHP', 'HTML'], 'core_strengths' => [], 'competencies' => [], 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => [], 'custom_sections' => []], $extra);
    }

    private function setupProvider(): void
    {
        config(['services.gemini.key' => 'fake-test-key', 'services.gemini.model' => 'test-model']);
        Http::preventStrayRequests();
        $this->actingAs(User::factory()->create());
    }

    public function test_ai_returns_a_reviewable_draft_with_server_calculated_scores(): void
    {
        $this->setupProvider();
        $draft = ['structured_resume' => $this->structured(), 'changes' => ['Added confirmed PHP and HTML skills.'], 'unsupported_keywords' => ['CSS']];
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['finishReason' => 'STOP', 'content' => ['parts' => [['text' => json_encode($draft)]]]]]])]);
        $this->postJson('/checker/rewrite', [...$this->payload(), 'score' => 100])->assertOk()->assertJsonPath('before.score', 0)->assertJsonPath('after.score', 32)->assertJsonPath('after.keyword_score', 67)->assertJsonPath('structured_resume.skills', ['C#', 'Python', 'PHP', 'HTML']);
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

    public function test_auth_consent_and_confirmed_keywords_are_checked_before_sending_data(): void
    {
        Http::preventStrayRequests();
        $this->postJson('/checker/rewrite', $this->payload())->assertUnauthorized();
        $this->actingAs(User::factory()->create());
        $this->postJson('/checker/rewrite', [...$this->payload(), 'ai_consent' => false])->assertUnprocessable();
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
}
