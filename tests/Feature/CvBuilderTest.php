<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\VerifyEmailCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CvBuilderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_degree_grade_survives_draft_saving_and_rejects_overlong_values(): void
    {
        $draft = $this->payload();
        $draft['data']['education'][0]['grade'] = 'Upper Second Class (2:1)';
        $this->postJson('/get-started/draft', $draft)->assertOk()
            ->assertSessionHas('cv_builder.data.education.0.grade', 'Upper Second Class (2:1)');
        $draft['data']['education'][0]['grade'] = str_repeat('a', 201);
        $this->postJson('/get-started/draft', $draft)->assertUnprocessable()->assertJsonValidationErrors('data.education.0.grade');
    }

    public function test_questions_link_opens_first_step_without_discarding_draft(): void
    {
        $draft = $this->payload();
        $this->withSession(['cv_builder' => $draft])
            ->get(route('builder.start', ['questions' => 1]))
            ->assertOk()
            ->assertViewHas('draft', fn ($shown) => $shown['step'] === 1 && $shown['data'] === $draft['data'])
            ->assertSessionHas('cv_builder', $draft);
    }

    public function test_section_visibility_and_positions_survive_draft_saving(): void
    {
        $draft = $this->payload();
        $draft['data']['section_titles'] = ['education' => 'Academic Background'];
        $draft['data']['hidden_sections'] = ['skills'];
        $draft['data']['section_positions'] = [['section' => 'education', 'region' => 'cv-side', 'after' => null]];
        $this->postJson('/get-started/draft', $draft)->assertOk()
            ->assertSessionHas('cv_builder.data.section_titles.education', 'Academic Background')
            ->assertSessionHas('cv_builder.data.hidden_sections', ['skills'])
            ->assertSessionHas('cv_builder.data.section_positions', $draft['data']['section_positions']);
        $draft['data']['section_positions'][0]['region'] = 'invalid-selector';
        $this->postJson('/get-started/draft', $draft)->assertUnprocessable()
            ->assertJsonValidationErrors('data.section_positions.0.region');
    }

    public function test_templates_link_opens_gallery_without_discarding_draft(): void
    {
        $draft = $this->payload();
        $this->withSession(['cv_builder' => $draft])->get(route('builder.start', ['templates' => 1]))
            ->assertOk()->assertViewHas('draft', fn ($shown) => $shown['step'] === 3 && $shown['data'] === $draft['data'])
            ->assertSessionHas('cv_builder', $draft);
    }

    private function payload(): array
    {
        return ['step' => 4, 'data' => ['template' => 'double-column', 'name' => 'Alex Morgan', 'role' => 'Engineer', 'email' => 'alex@example.com', 'summary' => 'Real experience', 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => []]];
    }

    public function test_docx_import_rejects_invalid_xml_encoding_and_excess_text_without_truncation(): void
    {
        $documents = [
            '<w:document xmlns:w="urn:word"><w:body>Unclosed',
            '<w:document xmlns:w="urn:word"><w:body>'."\xFF".'</w:body></w:document>',
            '<w:document xmlns:w="urn:word"><w:body>'.str_repeat('A', 20001).'</w:body></w:document>',
        ];
        foreach ($documents as $xml) {
            $this->importDocxXml($xml)->assertUnprocessable()->assertJsonValidationErrors('file');
        }
    }

    public function test_docx_import_keeps_words_separated_at_tabs_and_table_cells(): void
    {
        $this->importDocxXml('<w:document xmlns:w="urn:word"><w:body><w:p><w:r><w:t>Alex</w:t><w:tab/><w:t>Morgan</w:t></w:r></w:p><w:tr><w:tc><w:p><w:r><w:t>PHP</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>SQL</w:t></w:r></w:p></w:tc></w:tr></w:body></w:document>')
            ->assertOk()->assertJsonPath('data.name', 'Alex Morgan');
    }

    private function importDocxXml(string $xml): TestResponse
    {
        $path = sys_get_temp_dir().'/ats-import-audit-'.bin2hex(random_bytes(8)).'.zip';
        $archive = new \PharData($path, 0, null, \Phar::ZIP);
        $archive['word/document.xml'] = $xml;
        unset($archive);
        try {
            return $this->postJson('/get-started/import', ['file' => new UploadedFile($path, 'audit.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true)]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_guest_can_answer_questions_and_save_a_session_draft(): void
    {
        Auth::logout();
        $this->get('/get-started')->assertOk()->assertSee('existing CV');
        $this->postJson('/get-started/draft', $this->payload())->assertOk()->assertSessionHas('cv_builder.data.name', 'Alex Morgan');
        $this->assertDatabaseCount('resumes', 0);
        $this->get('/get-started/finish')->assertRedirect('/login');
    }

    public function test_guest_can_import_and_continue_after_sign_in_without_losing_template(): void
    {
        Auth::logout();
        $this->importDocxXml('<w:document xmlns:w="urn:word"><w:body><w:p><w:r><w:t>Alex Morgan</w:t></w:r></w:p><w:p><w:r><w:t>alex@example.com</w:t></w:r></w:p></w:body></w:document>')
            ->assertOk()->assertJsonPath('data.email', 'alex@example.com');
        $draft = $this->payload();
        $this->postJson('/get-started/draft', $draft)->assertOk();
        $this->get('/get-started/continue')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect(route('builder.continue'));
        $this->get('/get-started/continue')->assertRedirect(route('builder.start'));
        $this->get('/get-started')->assertOk()->assertViewHas('draft', $draft);
    }

    public function test_guest_upload_rejects_files_larger_than_two_megabytes(): void
    {
        Auth::logout();
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->create('resume.pdf', 2049, 'application/pdf')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_draft_survives_login_and_is_saved_once_to_the_owner(): void
    {
        Auth::logout();
        $this->withSession(['cv_builder' => $this->payload()]);
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
        Auth::logout();
        $this->withSession(['cv_builder' => $this->payload()]);
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
            $this->postJson('/get-started/import', ['file' => $file])->assertOk()->assertJsonPath('email', 'alex@example.com')->assertJsonPath('data.name', 'Alex Morgan')->assertJsonPath('data.email', 'alex@example.com')->assertJsonPath('text', "Alex Morgan\nalex@example.com");
            $this->assertDatabaseCount('resumes', 0);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_additional_sections_are_saved_and_validated(): void
    {
        $payload = $this->payload();
        $payload['data']['competencies'] = 'Leadership';
        $payload['data']['references'] = 'Available on request';
        $payload['data']['custom_sections'] = [['title' => 'Projects', 'content' => 'Community website']];
        $this->postJson('/get-started/draft', $payload)->assertOk()
            ->assertSessionHas('cv_builder.data.custom_sections.0.title', 'Projects')
            ->assertSessionHas('cv_builder.data.competencies', 'Leadership')
            ->assertSessionHas('cv_builder.data.references', 'Available on request');
        $payload['data']['custom_sections'][0]['content'] = str_repeat('a', 5001);
        $this->postJson('/get-started/draft', $payload)->assertUnprocessable()->assertJsonValidationErrors('data.custom_sections.0.content');
    }

    public function test_invalid_and_oversized_uploads_are_rejected(): void
    {
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->create('malware.php', 1, 'text/plain')])->assertUnprocessable();
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->create('large.pdf', 5121, 'application/pdf')])->assertUnprocessable();
    }

    public function test_saving_from_another_tab_updates_only_the_requested_cv(): void
    {
        $user = User::factory()->create();
        $first = $user->resumes()->create(['name' => 'First CV', 'content' => $this->payload()['data']]);
        $second = $user->resumes()->create(['name' => 'Second CV', 'content' => $this->payload()['data']]);
        $payload = $this->payload();
        $payload['resume_id'] = $first->id;
        $payload['data']['name'] = 'First Updated';
        $this->actingAs($user)->withSession(['cv_builder' => ['resume_id' => $second->id]])
            ->postJson('/get-started/draft', $payload)->assertOk();
        $this->assertSame('First Updated CV', $first->fresh()->name);
        $this->assertSame('Second CV', $second->fresh()->name);
    }

    public function test_new_draft_does_not_overwrite_a_cv_open_in_another_tab(): void
    {
        $user = User::factory()->create();
        $saved = $user->resumes()->create(['name' => 'Keep This CV']);
        $payload = $this->payload();
        $payload['resume_id'] = null;
        $this->actingAs($user)->withSession(['cv_builder' => ['resume_id' => $saved->id]])
            ->postJson('/get-started/draft', $payload)->assertOk();
        $this->assertSame('Keep This CV', $saved->fresh()->name);
        $this->assertDatabaseCount('resumes', 1);
    }

    public function test_requested_cv_ids_are_checked_against_the_signed_in_owner(): void
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $resume = $owner->resumes()->create(['name' => 'Private CV']);
        $payload = $this->payload();
        $payload['resume_id'] = $resume->id;
        $this->actingAs($other)->postJson('/get-started/draft', $payload)->assertNotFound();
        $this->assertSame('Private CV', $resume->fresh()->name);
    }

    public function test_core_strengths_are_saved_separately_and_legacy_imports_are_restored(): void
    {
        $payload = $this->payload();
        $payload['data']['core_strengths'] = 'Adaptability';
        $payload['data']['competencies'] = 'Project planning';
        $this->postJson('/get-started/draft', $payload)->assertOk()
            ->assertSessionHas('cv_builder.data.core_strengths', 'Adaptability')
            ->assertSessionHas('cv_builder.data.competencies', 'Project planning');
        $legacy = $this->payload();
        $legacy['data']['competencies'] = 'Adaptability';
        $legacy['data']['imported_text'] = "Alex Morgan\nCore Strengths\nAdaptability\nReferences\nAvailable on request";
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => $legacy])->get('/get-started')->assertOk()
            ->assertViewHas('draft', fn ($draft) => $draft['data']['core_strengths'] === 'Adaptability' && $draft['data']['competencies'] === '');
    }

    public function test_new_cv_redirect_removes_the_reset_flag_before_editing(): void
    {
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => $this->payload()])->get('/get-started?new=1')
            ->assertRedirect(route('builder.start'))->assertSessionMissing('cv_builder');
        $this->postJson('/get-started/draft', $this->payload())->assertOk();
        $this->get('/get-started')->assertOk()->assertSessionHas('cv_builder.data.name', 'Alex Morgan');
    }

    public function test_removed_slate_drafts_open_as_elegant_without_losing_content(): void
    {
        $draft = $this->payload();
        $draft['data']['template'] = 'slate';
        $this->actingAs(User::factory()->create())->withSession(['cv_builder' => $draft])->get('/get-started')->assertOk()
            ->assertViewHas('draft', fn ($draft) => $draft['data']['template'] === 'elegant' && $draft['data']['name'] === 'Alex Morgan');
    }
}
