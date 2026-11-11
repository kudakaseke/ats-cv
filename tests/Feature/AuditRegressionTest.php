<?php

namespace Tests\Feature;

use App\Models\CvAnalysis;
use App\Models\SubscriptionPlan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class AuditRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_normalizes_email_and_rejects_non_string_login_email(): void
    {
        Notification::fake();
        $this->post('/register', ['name' => 'Audit User', 'email' => 'AUDIT@example.invalid', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['email' => 'audit@example.invalid']);
        $this->post('/logout');
        $this->postJson('/login', ['email' => ['invalid'], 'password' => 'password'])->assertUnprocessable();
    }

    public function test_password_change_rotates_remember_token_and_revokes_other_database_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $oldToken = $user->remember_token;
        foreach (['old-device' => $user->id, 'unrelated-device' => $other->id] as $id => $owner) {
            DB::table('sessions')->insert(['id' => $id, 'user_id' => $owner, 'payload' => '', 'last_activity' => time()]);
        }
        config(['session.driver' => 'database']);
        $this->actingAs($user)->put('/password', ['current_password' => 'password', 'password' => 'changed-password', 'password_confirmation' => 'changed-password'])
            ->assertSessionHasNoErrors();
        $this->assertNotSame($oldToken, $user->fresh()->remember_token);
        $this->assertDatabaseMissing('sessions', ['id' => 'old-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'unrelated-device']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_export_cleanup_only_removes_old_generated_files_and_supports_dry_run(): void
    {
        $original = storage_path();
        $root = sys_get_temp_dir().'/ats-audit-'.bin2hex(random_bytes(8));
        $directory = $root.'/app/private/cv-exports';
        mkdir($directory, 0777, true);
        $old = $directory.'/11111111-1111-4111-8111-111111111111.pdf';
        $recent = $directory.'/22222222-2222-4222-8222-222222222222.docx';
        $unrelated = $directory.'/customer.pdf';
        foreach ([$old, $recent, $unrelated] as $file) {
            file_put_contents($file, 'fixture');
        }
        touch($old, time() - 172800);
        touch($unrelated, time() - 172800);
        $this->app->useStoragePath($root);
        try {
            $this->artisan('cv:prune-exports', ['--dry-run' => true])->assertSuccessful();
            $this->assertFileExists($old);
            $this->artisan('cv:prune-exports')->assertSuccessful();
            $this->assertFileDoesNotExist($old);
            $this->assertFileExists($recent);
            $this->assertFileExists($unrelated);
        } finally {
            $this->app->useStoragePath($original);
            foreach ([$old, $recent, $unrelated] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($directory);
            rmdir(dirname($directory));
            rmdir(dirname($directory, 2));
            rmdir($root);
        }
    }

    public function test_guest_drafts_remain_available_but_downloads_require_login_and_verification(): void
    {
        $this->postJson('/get-started/export')->assertUnauthorized();
        $this->postJson('/get-started/draft')->assertUnprocessable();
        $this->actingAs(User::factory()->unverified()->create());
        $this->postJson('/get-started/export')->assertForbidden();
        $this->get('/get-started')->assertOk();
    }

    public function test_fresh_database_has_a_default_plan_without_manual_seeding(): void
    {
        $this->assertSame(475, SubscriptionPlan::where('slug', 'monthly')->firstOrFail()->price_cents);
        $this->actingAs(User::factory()->create())->get('/dashboard')->assertOk();
        $this->get('/pricing')->assertOk()->assertSee('$3.95');
    }

    public function test_scan_allowance_is_enforced_and_results_are_saved_without_creating_cvs(): void
    {
        config(['services.openai.key' => 'fake-key']);
        Http::fake(['api.openai.com/*' => Http::response(['status' => 'completed', 'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => json_encode(['summary' => 'Comparison complete.', 'recommendations' => []])]]]]])]);
        $user = User::factory()->create();
        $this->actingAs($user);
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/checker/scan', ['job_description' => 'PHP SQL', 'resume_text' => 'PHP developer'])->assertOk()->assertJsonStructure(['analysis_id']);
        }
        $this->postJson('/checker/scan', ['job_description' => 'PHP SQL', 'resume_text' => 'PHP developer'])
            ->assertUnprocessable()->assertJsonValidationErrors('credits');
        $this->assertSame(3, $user->analyses()->where('status', 'completed')->count());
        $this->assertDatabaseCount('resumes', 0);
        $this->get('/dashboard')->assertOk();
        $this->get('/workspace/history')->assertOk()->assertSee('View result');
    }

    public function test_saved_results_are_private_and_markup_is_escaped(): void
    {
        $owner = User::factory()->create();
        $analysis = $owner->analyses()->create(['job_description' => '<script>alert(1)</script>', 'status' => 'completed', 'score' => 10,
            'results' => ['similarity_percent' => 10, 'matched' => ['PHP'], 'missing' => ['SQL']]]);
        $this->actingAs($owner)->get('/analyses/'.$analysis->id)->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $this->actingAs(User::factory()->create())->get('/analyses/'.$analysis->id)->assertNotFound();
        $this->get('/workspace/history')->assertOk()->assertDontSee('View result');
    }

    public function test_saved_cv_limit_preserves_the_draft_and_existing_cv(): void
    {
        $user = User::factory()->create();
        $user->resumes()->create(['name' => 'Existing CV']);
        $draft = ['step' => 4, 'data' => ['name' => 'New draft', 'template' => 'essential']];
        $this->actingAs($user)->withSession(['cv_builder' => $draft])->post('/get-started/finish')
            ->assertSessionHasErrors('resume_limit')->assertSessionHas('cv_builder.data.name', 'New draft');
        $this->assertSame('Existing CV', $user->resumes()->sole()->name);
    }

    public function test_deeply_nested_structured_json_returns_validation_instead_of_server_error(): void
    {
        $nested = str_repeat('{"x":', 40).'null'.str_repeat('}', 40);
        $this->actingAs(User::factory()->create())->postJson('/checker/edit', ['resume_text' => 'Alex Morgan', 'generated' => true, 'structured_resume' => $nested])
            ->assertUnprocessable()->assertJsonValidationErrors('structured_resume');
    }

    public function test_private_and_authentication_pages_are_not_stored_in_shared_browser_cache(): void
    {
        $this->get('/login')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff');
        $response = $this->actingAs(User::factory()->create())->get('/dashboard');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_deleting_an_owned_cv_frees_the_slot_and_other_users_cannot_delete_it(): void
    {
        $owner = User::factory()->create();
        $resume = $owner->resumes()->create(['name' => 'Owned CV']);
        $this->actingAs(User::factory()->create())->delete('/cv/'.$resume->id)->assertNotFound();
        $this->actingAs($owner)->withSession(['cv_builder' => ['resume_id' => $resume->id]])->delete('/cv/'.$resume->id)
            ->assertRedirect(route('workspace.module', 'resumes'))->assertSessionMissing('cv_builder');
        $this->assertSame(0, $owner->resumes()->count());
    }

    public function test_account_deletion_removes_saved_checks(): void
    {
        $user = User::factory()->create();
        $user->analyses()->create(['job_description' => 'PHP', 'status' => 'completed', 'score' => 100]);
        $this->actingAs($user)->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame(0, CvAnalysis::count());
    }
}
