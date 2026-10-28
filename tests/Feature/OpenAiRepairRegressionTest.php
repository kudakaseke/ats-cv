<?php
namespace Tests\Feature;

use App\Models\User;
use App\Services\CvWordingMatch;
use App\Services\CvAiScanContract;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class OpenAiRepairRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_identical_completed_scan_reuses_result_under_a_new_request_id(): void
    {
        config(['services.openai.key' => 'fake-key', 'services.openai.model' => 'gpt-6.1-sol']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'model' => 'gpt-6.1-sol',
            'usage' => ['input_tokens' => 10, 'output_tokens' => 5],
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['summary' => 'Checked.', 'recommendations' => []])]]]]])]);
        $user = User::factory()->create();
        $input = ['job_description' => 'Skills: PHP, SQL', 'resume_text' => 'PHP development'];
        $first = $this->actingAs($user)->postJson('/checker/scan', [...$input, 'operation_id' => (string) Str::uuid()])->assertOk();
        $wallet = app(\App\Services\AiCreditService::class)->wallet($user);
        $this->postJson('/checker/scan', [...$input, 'operation_id' => (string) Str::uuid()])->assertOk()
            ->assertJsonPath('keyword_score', $first->json('keyword_score'))->assertJsonPath('credits_used', 0)->assertJsonPath('reused_result', true);
        Http::assertSentCount(1);
        $this->assertSame($wallet, app(\App\Services\AiCreditService::class)->wallet($user));
        $this->postJson('/checker/scan', [...$input, 'resume_text' => 'PHP and SQL development', 'operation_id' => (string) Str::uuid()])->assertOk();
        Http::assertSentCount(2);
    }

    public function test_wording_improves_only_when_supported_terms_are_actually_added(): void
    {
        $assessment = ['requirements' => [
            ['category' => 'skills', 'importance' => 'mandatory', 'requirement' => 'Technical documentation', 'original_status' => 'matched', 'evidence' => 'Wrote user guides'],
            ['category' => 'certifications', 'importance' => 'mandatory', 'requirement' => 'Azure certification', 'original_status' => 'not_evidenced', 'evidence' => ''],
        ]];
        $matcher = app(CvWordingMatch::class);
        $this->assertSame(0, $matcher->score($assessment, 'Wrote user guides', 'Wrote user guides')['keyword_score']);
        $after = $matcher->score($assessment, 'Wrote user guides', "Technical documentation: wrote user guides.\nAzure certification");
        $this->assertSame(50, $after['keyword_score']);
        $this->assertSame(['Azure certification'], $after['missing']);
        $this->assertSame(0, $matcher->score($assessment, 'Wrote user guides', 'No technical documentation')['keyword_score']);
    }

    public function test_compact_contract_does_not_request_duplicate_category_arithmetic(): void
    {
        $schema = app(CvAiScanContract::class)->schema();
        $this->assertArrayNotHasKey('categories', $schema['properties']);
        $this->assertArrayNotHasKey('candidate_profile', $schema['properties']);
        $this->assertCount(7, $schema['properties']['requirements']['items']['properties']);
        $expanded = app(CvAiScanContract::class)->expand(['schema_version' => '2.0', 'requirements' => [
            ['category' => 'skills', 'match_classification' => 'DIRECT_MATCH'],
            ['category' => 'responsibilities', 'match_classification' => 'PARTIAL_MATCH'],
        ]], 'Skills: PHP');
        $this->assertSame('matched', $expanded['requirements'][0]['original_status']);
        $this->assertSame('partially_evidenced', $expanded['requirements'][1]['original_status']);
        $this->assertCount(6, $expanded['categories']);
    }
    public function test_uncertain_years_cannot_fully_satisfy_a_duration_requirement(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 29));
        try {
            $assessment = \Tests\Fixtures\MasterCvV2::assessment();
            $assessment['requirements'] = [['category' => 'experience', 'requirement' => '3 years of development experience',
                'importance' => 'mandatory', 'evidence' => 'Freelance Developer2025 - Present',
                'original_status' => 'matched', 'revamped_status' => 'matched', 'match_classification' => 'DIRECT_MATCH']];
            $source = "Experience\nFreelance Developer2025 - Present\nBuild websites.\n\nICT Assistant, Attachment2023 - 2024\nUser support.";
            $guarded = app(\App\Services\CvAiV2Report::class)->sourceGuard($assessment, $source);
            $this->assertSame('uncertain', $guarded['requirements'][0]['original_status']);
            $this->assertSame('UNCERTAIN', $guarded['requirements'][0]['match_classification']);
            $this->assertNull($guarded['experience']['documented_relevant_years']);
        } finally { $this->travelBack(); }
    }
}
