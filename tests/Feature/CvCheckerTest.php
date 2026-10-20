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

        $this->actingAs(User::factory()->create())->get('/workspace/checker')->assertOk()->assertSee('CV Checker');
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
            ->assertSessionHas('cv_builder.data.skills', 'C#, Python, PHP, HTML')->assertSessionHas('cv_builder.data.template', 'ats-professional');
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
        $this->get('/get-started')->assertOk()->assertSee('The text similarity has not increased.')
            ->assertSee('Compare original and tailored text')->assertSee('Requirements still missing');
    }
}
