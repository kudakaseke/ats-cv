<?php

namespace Tests\Feature;

use App\Http\Middleware\AuthenticateAccountSession;
use App\Models\User;
use App\Services\AccountSessions;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class AuditFixesTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_navigation_preserves_drafts_and_does_not_save_a_cv(): void
    {
        $user = User::factory()->create();
        $draft = ['step' => 4, 'data' => ['name' => 'Audit Candidate', 'template' => 'essential']];
        $this->actingAs($user)->withSession(['cv_builder' => $draft])
            ->get('/get-started?new=1')->assertOk()->assertSessionHas('cv_builder', $draft);
        $this->get('/get-started/finish')->assertOk()->assertSessionHas('cv_builder', $draft);
        $this->assertDatabaseCount('resumes', 0);
        $saved = $user->resumes()->create(['name' => 'Another CV', 'content' => ['name' => 'Another Candidate', 'template' => 'essential']]);
        $this->get(route('builder.edit', $saved))->assertOk()->assertSessionHas('cv_builder', $draft);
        $saved->delete();
        $this->post('/get-started/finish')->assertRedirect();
        $this->assertDatabaseCount('resumes', 1);
    }

    public function test_mutations_require_csrf_even_for_guests(): void
    {
        $this->app->instance('env', 'production');
        $draft = ['step' => 4, 'data' => ['name' => 'Keep me', 'template' => 'essential']];
        $this->withSession(['cv_builder' => $draft, '_token' => 'audit-csrf-token'])
            ->post('/get-started/new')->assertStatus(419)->assertSessionHas('cv_builder', $draft);
        $this->post('/get-started/new', ['_token' => 'audit-csrf-token'])
            ->assertRedirect('/get-started')->assertSessionMissing('cv_builder');
        $user = User::factory()->create();
        $this->actingAs($user)->withSession(['cv_builder' => $draft])
            ->post('/get-started/finish')->assertStatus(419);
        $this->assertDatabaseCount('resumes', 0);
    }

    public function test_docx_prefixes_and_default_and_strict_namespaces_preserve_boundaries(): void
    {
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);
        foreach ([['w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'], ['x', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'], ['', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main'], ['s', 'http://purl.oclc.org/ooxml/wordprocessingml/main']] as [$prefix, $namespace]) {
            $p = $prefix === '' ? '' : $prefix.':';
            $declaration = $prefix === '' ? 'xmlns' : 'xmlns:'.$prefix;
            $xml = '<'.$p.'document '.$declaration.'="'.$namespace.'"><'.$p.'body><'.$p.'p><'.$p.'r><'.$p.'t>Alex &amp; Casey</'.$p.'t><'.$p.'tab/><'.$p.'t>Morgan</'.$p.'t></'.$p.'r></'.$p.'p><'.$p.'p><'.$p.'r><'.$p.'t>Skills</'.$p.'t></'.$p.'r></'.$p.'p><'.$p.'tbl><'.$p.'tr><'.$p.'tc><'.$p.'p><'.$p.'r><'.$p.'t>PHP</'.$p.'t></'.$p.'r></'.$p.'p></'.$p.'tc><'.$p.'tc><'.$p.'p><'.$p.'r><'.$p.'t>SQL</'.$p.'t><'.$p.'br/><'.$p.'t>Excel</'.$p.'t></'.$p.'r></'.$p.'p></'.$p.'tc></'.$p.'tr></'.$p.'tbl></'.$p.'body></'.$p.'document>';
            $path = sys_get_temp_dir().'/audit-docx-'.bin2hex(random_bytes(6)).'.zip';
            $zip = new \PharData($path, 0, null, \Phar::ZIP);
            $zip['word/document.xml'] = $xml;
            unset($zip);
            try {
                $response = $this->postJson('/get-started/import', ['file' => new UploadedFile($path, 'audit.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true)])->assertOk();
                $this->assertSame('Alex & Casey Morgan Skills PHP SQL Excel', preg_replace('/\s+/u', ' ', $response->json('text')));
                $this->assertStringContainsString("\nSkills\n", $response->json('text'));
            } finally { unlink($path); }
        }
    }

    private function otherFileSession(User $user): \Illuminate\Session\Store
    {
        $store = new \Illuminate\Session\Store('audit-other-browser', Session::driver('file')->getHandler());
        $store->setId(bin2hex(random_bytes(20)));
        $store->start();
        $store->put(Auth::guard('web')->getName(), $user->id);
        $store->put('password_hash_web', Auth::guard('web')->hashPasswordForCookie($user->getAuthPassword()));
        $store->put('cv_builder', ['data' => ['name' => 'Private synthetic CV']]);
        $store->save();

        return $store;
    }

    private function requestFromSession(\Illuminate\Session\Store $session): Request
    {
        Auth::forgetGuards();
        $this->app->instance('session.store', $session);
        $request = Request::create('/dashboard');
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => Auth::guard('web')->user());

        return $request;
    }

    private function assertSessionRejected(\Illuminate\Session\Store $session): void
    {
        try {
            app(AuthenticateAccountSession::class)->handle($this->requestFromSession($session), fn () => response('should not run'));
            $this->fail('The old session must require a fresh sign-in.');
        } catch (AuthenticationException) {
            $this->assertFalse(Auth::check());
            $this->assertFalse($session->has('cv_builder'));
        } finally { $session->getHandler()->destroy($session->getId()); }
    }

    public function test_file_session_is_rejected_after_password_change(): void
    {
        $user = User::factory()->create();
        config(['session.driver' => 'file']);
        $this->actingAs($user);
        $other = $this->otherFileSession($user);
        $this->put('/password', ['current_password' => 'password', 'password' => 'Changed-password-123!', 'password_confirmation' => 'Changed-password-123!'])->assertSessionHasNoErrors();
        $this->assertTrue(Hash::check('Changed-password-123!', $user->fresh()->password));
        $this->assertSessionRejected($other);
    }

    public function test_unchanged_file_session_remains_valid_and_is_registered(): void
    {
        $user = User::factory()->create();
        config(['session.driver' => 'file']);
        $other = $this->otherFileSession($user);
        try {
            $response = app(AuthenticateAccountSession::class)->handle($this->requestFromSession($other), fn () => response('authenticated'));
            $this->assertSame('authenticated', $response->getContent());
            $this->assertSame($user->id, Auth::id());
            $this->assertDatabaseHas('account_sessions', ['id' => $other->getId(), 'user_id' => $user->id, 'driver' => 'file']);
        } finally { $other->getHandler()->destroy($other->getId()); }
    }

    public function test_file_session_is_rejected_after_password_recovery(): void
    {
        $user = User::factory()->create();
        $other = $this->otherFileSession($user);
        $token = Password::broker()->createToken($user);
        config(['session.driver' => 'file']);
        $this->post('/reset-password', ['email' => $user->email, 'token' => $token, 'password' => 'Recovered-password-123!', 'password_confirmation' => 'Recovered-password-123!'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('Recovered-password-123!', $user->fresh()->password));
        $this->assertSessionRejected($other);
    }

    public function test_file_session_is_rejected_after_initial_password_setup(): void
    {
        $user = User::factory()->create();
        $user->forceFill(['google_id' => 'audit-google', 'password_setup_required' => true, 'password_set_at' => null])->save();
        config(['session.driver' => 'file']);
        $this->actingAs($user);
        $other = $this->otherFileSession($user);
        $this->post('/set-password', ['email' => $user->email, 'password' => 'Initial-password-123!', 'password_confirmation' => 'Initial-password-123!'])->assertRedirect('/profile');
        $this->assertSessionRejected($other);
    }

    public function test_unfingerprinted_legacy_session_is_rejected(): void
    {
        $user = User::factory()->create();
        $other = $this->otherFileSession($user);
        $other->forget('password_hash_web');
        $this->assertSessionRejected($other);
    }

    public function test_deletion_erases_registered_file_and_database_sessions_and_recovery_tokens(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $other = $this->otherFileSession($user);
        DB::table('account_sessions')->insert(['id' => $other->getId(), 'user_id' => $user->id, 'driver' => 'file', 'last_activity' => time()]);
        DB::table('sessions')->insert(['id' => 'old-database-session', 'user_id' => $user->id, 'payload' => base64_encode('Synthetic private data'), 'last_activity' => time()]);
        Password::broker()->createToken($user);
        $this->delete('/profile', ['password' => 'password'])->assertRedirect('/');
        $this->assertSame('', $other->getHandler()->read($other->getId()));
        $this->assertDatabaseMissing('sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('account_sessions', ['user_id' => $user->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
    }

    public function test_deleted_account_cannot_reappear_as_a_guest_with_a_private_draft(): void
    {
        $user = User::factory()->create();
        $other = $this->otherFileSession($user);
        $user->delete();
        try {
            $response = app(AuthenticateAccountSession::class)->handle($this->requestFromSession($other), fn () => response('guest'));
            $this->assertSame('guest', $response->getContent());
            $this->assertFalse($other->has('cv_builder'));
        } finally { $other->getHandler()->destroy($other->getId()); }
    }
}
