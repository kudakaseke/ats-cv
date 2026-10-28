<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CvPdfExtractor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CvCheckerTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_saved_scores_are_not_presented_as_new_weighted_scores(): void
    {
        $user = User::factory()->create();
        $analysis = $user->analyses()->create(['job_description' => 'Skills: Excel, Python', 'status' => 'completed', 'score' => 69,
            'results' => ['keyword_score' => 69, 'certificate_bonus' => 2, 'matched' => ['Excel'], 'missing' => ['Python']]]);
        $this->actingAs($user)->get(route('workspace.analysis', $analysis))->assertOk()
            ->assertSee('previous wording method (69/100)')->assertSee('Not available for this check')
            ->assertDontSee('Includes 2 additional certificate bonus points');
        $this->assertSame(69, $analysis->fresh()->results['keyword_score']);
    }

    public function test_scan_cached_retry_and_saved_check_share_the_same_experience_assessment(): void
    {
        $this->mock(\App\Services\CvAiRewriter::class, function ($mock) {
            $mock->shouldReceive('analyse')->once()->andReturn([
                'summary' => 'The CV documents ten years of relevant procurement experience.',
                'recommendations' => [],
                'assessment' => ['original_score' => ['total' => 72.22], 'mandatory_requirements_status' => 'review_required',
                    'categories' => [], 'requirements' => [], 'warnings' => [], 'questions_for_candidate' => [], 'experience' => [
                    'required_years' => 4, 'documented_relevant_years' => 10, 'total_professional_years' => 13, 'date_warnings' => [],
                ]],
            ]);
        });
        $user = User::factory()->create();
        $input = ['operation_id' => (string) \Illuminate\Support\Str::uuid(), 'job_description' => '4 years of experience. Skills: Procurement', 'resume_text' => "Alex Example\nExperience\nProcurement Officer\nJanuary 2010 - December 2022"];
        $response = $this->actingAs($user)->postJson('/checker/scan', $input)->assertOk()
            ->assertJsonPath('experience_check.cv_years', 13)->assertJsonPath('experience_check.relevant_years', 10)
            ->assertJsonPath('experience_check.shortfall', false);
        $this->postJson('/checker/scan', $input)->assertOk()->assertJsonPath('experience_check.relevant_years', 10)
            ->assertJsonPath('compatibility_score', $response->json('compatibility_score'));
        $analysis = $user->analyses()->findOrFail($response->json('analysis_id'));
        // Simulate a previously saved check carrying the old contradictory warning.
        $results = $analysis->results;
        $results['experience_check'] = app(\App\Services\CvExperienceMatcher::class)->compare($input['job_description'], $input['resume_text']);
        $analysis->update(['results' => $results]);
        $this->get(route('workspace.analysis', $analysis))->assertOk()
            ->assertSee('Approximately 10 years were assessed as relevant')
            ->assertDontSee('We could not estimate your experience');
    }

    public function test_scan_handles_smart_quotes_and_records_upgraded_model_usage(): void
    {
        config(['services.openai.key' => 'fake-key', 'services.openai.model' => 'gpt-4.1']);
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['summary' => 'Skills matched.', 'recommendations' => []])]]]],
        ])]);

        $this->actingAs(User::factory()->create())->postJson('/checker/scan', [
            'job_description' => "Skills\n• ‘Customer service’ •", 'resume_text' => "Skills\nCustomer service",
        ])->assertOk()->assertJsonPath('keyword_score', 100)->assertJsonPath('ai_analysis.summary', 'Skills matched.');

        Http::assertSent(fn ($request) => $request->url() === 'https://api.openai.com/v1/responses' && $request['model'] === 'gpt-4.1');
        $this->assertDatabaseHas('ai_usage_logs', ['model' => 'gpt-4.1', 'feature' => 'analyse', 'status' => 'completed', 'input_tokens' => 100, 'output_tokens' => 30]);
    }

    public function test_checker_endpoints_require_a_verified_account(): void
    {
        $this->postJson('/checker/scan', [])->assertUnauthorized();
        $this->post('/checker/edit', [])->assertRedirect('/login');
        $this->actingAs(User::factory()->unverified()->create())->postJson('/checker/scan', [])->assertForbidden();
    }

    public function test_scan_calculates_scores_from_submitted_text_instead_of_client_scores(): void
    {
        config(['services.openai.key' => 'fake-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['summary' => 'Comparison complete.', 'recommendations' => []])]]]]])]);

        $this->actingAs(User::factory()->create())->get('/workspace/checker')->assertOk()->assertSee('Job-to-CV ATS Detector');
        $this->postJson('/checker/scan', ['job_description' => 'PHP HTML CSS', 'resume_text' => 'C# Python', 'score' => 100])->assertOk()->assertJsonPath('score', 0)->assertJsonPath('missing', ['PHP', 'HTML', 'CSS']);
        $this->postJson('/checker/scan', ['job_description' => 'PHP HTML CSS', 'resume_text' => "C# Python\nSkills\nPHP HTML"])->assertOk()->assertJsonPath('score', 41)->assertJsonPath('keyword_score', 67);
        $this->postJson('/checker/scan', ['job_description' => 'and the', 'resume_text' => 'Python'])->assertUnprocessable()->assertJsonValidationErrors('job_description');
        $this->postJson('/checker/scan', ['job_description' => 'PHP', 'resume_text' => ''])->assertUnprocessable();
    }

    public function test_text_upload_and_builder_handoff_preserve_revised_skills(): void
    {
        $text = "Alex Morgan\nalex@example.com\nSkills\nC#, Python, PHP, HTML";
        $this->actingAs(User::factory()->create())->post('/checker/upload', ['file' => UploadedFile::fake()->createWithContent('resume.txt', $text)], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('text', $text);
        $this->post('/checker/edit', ['resume_text' => $text])->assertRedirect(route('builder.start'))
            ->assertSessionHas('cv_builder.data.skills', 'C#, Python, PHP, HTML')->assertSessionHas('cv_builder.data.template', 'essential');
        $this->assertDatabaseCount('resumes', 0);
        $this->post('/checker/upload', ['file' => UploadedFile::fake()->createWithContent('bad.txt', "\xff\xfe")], ['Accept' => 'application/json'])->assertUnprocessable();
    }

    public function test_generated_cv_opens_in_review_with_recalculated_before_and_after_scores(): void
    {
        $this->actingAs(User::factory()->create())->post('/checker/edit', [
            'resume_text' => "Alex Morgan\nSkills\nHTML, CSS", 'original_resume_text' => "Alex Morgan\nSkills\nHTML",
            'job_description' => 'HTML CSS JavaScript', 'generated' => '1', 'score' => 100,
        ])->assertRedirect(route('builder.start'))->assertSessionHas('cv_builder.review_ready', true)
            ->assertSessionHas('cv_builder.checker_result.before', 20.2)->assertSessionHas('cv_builder.checker_result.after', 41.12);
        $this->get('/get-started')->assertOk()->assertSee('Your tailored CV is ready.');
        $this->post('/checker/edit', ['resume_text' => 'Alex', 'generated' => '1'], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors(['original_resume_text', 'job_description']);
    }

    public function test_pdf_upload_uses_the_bounded_pymupdf_extractor(): void
    {
        $this->mock(CvPdfExtractor::class, function ($mock) {
            $mock->shouldReceive('extract')->once()->andReturn(['text' => 'PHP HTML CSS', 'pages' => 1, 'extractor' => 'pymupdf']);
        });
        $this->actingAs(User::factory()->create())->post('/checker/upload', ['file' => UploadedFile::fake()->create('resume.pdf', 4, 'application/pdf')], ['Accept' => 'application/json'])
            ->assertOk()->assertJsonPath('extractor', 'pymupdf')->assertJsonPath('text', 'PHP HTML CSS');
    }

    public function test_unchanged_cv_never_claims_a_score_improvement(): void
    {
        $text = "Alex Morgan\nSkills\nHTML";
        $this->actingAs(User::factory()->create())->post('/checker/edit', [
            'resume_text' => $text, 'original_resume_text' => $text,
            'job_description' => 'HTML CSS', 'generated' => '1', 'score' => 100,
        ])->assertRedirect(route('builder.start'));
        $comparison = session('cv_builder.checker_result');
        $this->assertSame($comparison['before'], $comparison['after']);
        $this->assertSame([], $comparison['added_keywords']);
        $this->get('/get-started')->assertOk()->assertDontSee('Wording Match has not increased.')
            ->assertSee('Compare original and tailored text')->assertSee('Requirements still missing');
    }
}
