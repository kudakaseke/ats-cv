<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CvBuilderTest extends TestCase
{
    use RefreshDatabase;

    private function payload(): array
    {
        return ['step' => 4, 'data' => ['template' => 'double-column', 'name' => 'Alex Morgan', 'role' => 'Engineer', 'email' => 'alex@example.com', 'summary' => 'Real experience', 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => []]];
    }

    public function test_guest_can_start_and_save_a_draft_without_an_account(): void
    {
        $this->get('/get-started')->assertOk()->assertSee('existing CV')->assertViewHas('templates', fn ($templates) => count($templates) === 20);
        $this->postJson('/get-started/draft', $this->payload())->assertOk()->assertSessionHas('cv_builder.data.name', 'Alex Morgan');
        $this->assertDatabaseCount('resumes', 0);
        $this->get('/get-started/finish')->assertRedirect('/login')->assertSessionHas('url.intended', route('builder.finish'));
    }

    public function test_draft_survives_login_and_is_saved_once_to_the_owner(): void
    {
        $this->postJson('/get-started/draft', $this->payload())->assertOk();
        $this->get('/get-started/finish')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('builder.finish'));
        $this->get('/get-started/finish')->assertRedirect();
        $resume = $user->resumes()->firstOrFail();
        $this->assertSame('double-column', $resume->content['template']);
        $this->get('/get-started/finish')->assertRedirect(route('builder.edit', $resume));
        $this->assertDatabaseCount('resumes', 1);
        $this->get(route('builder.edit', $resume))->assertOk();
        $payload = $this->payload();
        $payload['data']['name'] = 'Updated Name';
        $this->postJson('/get-started/draft', $payload)->assertOk();
        $this->assertSame('Updated Name CV', $resume->fresh()->name);
    }

    public function test_new_signup_and_email_code_return_to_the_draft(): void
    {
        Notification::fake();
        $this->postJson('/get-started/draft', $this->payload())->assertOk();
        $this->get('/get-started/finish')->assertRedirect('/login');
        $this->post('/register', ['name' => 'Alex Morgan', 'email' => 'alex@example.com', 'password' => 'password', 'password_confirmation' => 'password'])->assertRedirect('/verify-email');
        $user = User::where('email', 'alex@example.com')->firstOrFail();
        $code = '';
        Notification::assertSentTo($user, VerifyEmailCode::class, function ($notification) use (&$code) {
            $code = $notification->code;

            return true;
        });
        $this->post('/verify-email', ['code' => $code])->assertRedirect(route('builder.finish'));
        $this->get('/get-started/finish')->assertRedirect();
        $this->assertDatabaseHas('resumes', ['user_id' => $user->id, 'name' => 'Alex Morgan CV']);
    }

    public function test_unverified_accounts_cannot_save_or_edit_account_cvs(): void
    {
        $user = User::factory()->unverified()->create();
        $resume = $user->resumes()->create(['name' => 'Private CV']);
        $this->actingAs($user)->get(route('builder.edit', $resume))->assertRedirect('/verify-email');
        $this->get('/get-started/finish')->assertRedirect('/verify-email');
        $this->assertDatabaseCount('resumes', 1);
    }

    public function test_another_account_cannot_read_or_overwrite_a_cv(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $resume = $owner->resumes()->create(['name' => 'Private CV', 'content' => $this->payload()['data']]);
        $this->actingAs($other)->get(route('builder.edit', $resume))->assertNotFound();
        $this->withSession(['cv_builder' => ['resume_id' => $resume->id]])->postJson('/get-started/draft', $this->payload())->assertNotFound();
        $this->assertSame('Private CV', $resume->fresh()->name);
    }

    public function test_unknown_templates_and_oversized_content_are_rejected(): void
    {
        $payload = $this->payload();
        $payload['data']['template'] = 'unknown';
        $this->postJson('/get-started/draft', $payload)->assertUnprocessable()->assertJsonValidationErrors('data.template');
        $payload = $this->payload();
        $payload['data']['summary'] = str_repeat('a', 5001);
        $this->postJson('/get-started/draft', $payload)->assertUnprocessable()->assertJsonValidationErrors('data.summary');
    }

    public function test_docx_text_is_imported_and_original_file_is_not_stored(): void
    {
        $path = sys_get_temp_dir().'/ats-test-'.bin2hex(random_bytes(5)).'.zip';
        $archive = new \PharData($path, 0, null, \Phar::ZIP);
        $archive['[Content_Types].xml'] = '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
        $archive['word/document.xml'] = '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Alex Morgan</w:t></w:r></w:p><w:p><w:r><w:t>alex@example.com</w:t></w:r></w:p></w:body></w:document>';
        unset($archive);
        try {
            $file = new UploadedFile($path, 'cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
            $this->postJson('/get-started/import', ['file' => $file])->assertOk()->assertJsonPath('email', 'alex@example.com')->assertJsonPath('text', "Alex Morgan\nalex@example.com");
            $this->assertDatabaseCount('resumes', 0);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_invalid_and_oversized_uploads_are_rejected(): void
    {
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->create('malware.php', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')])->assertUnprocessable();
    }
}
