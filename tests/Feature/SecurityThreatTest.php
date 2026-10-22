<?php

namespace Tests\Feature;

use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class SecurityThreatTest extends TestCase
{
    use RefreshDatabase;

    public function test_host_header_poisoning_is_rejected_before_redirect_or_login(): void
    {
        $this->get('http://attacker.example/login')->assertStatus(400);
        $this->get('http://localhost.attacker.example/dashboard')->assertStatus(400);
        $this->get('http://localhost/login')->assertOk();
    }

    public function test_untrusted_forwarded_headers_cannot_poison_reset_links_or_bypass_ip_limits(): void
    {
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        for ($index = 0; $index < 3; $index++) {
            $this->withHeaders(['X-Forwarded-For' => '192.0.2.'.($index + 1), 'X-Forwarded-Host' => 'attacker.example'])
                ->post('/register', [])->assertRedirect();
        }
        $this->withHeaders(['X-Forwarded-For' => '192.0.2.99'])->post('/register', [])->assertStatus(429);
    }

    public function test_trace_and_connect_requests_are_not_accepted(): void
    {
        $this->call('TRACE', '/')->assertStatus(405);
        $this->call('CONNECT', '/')->assertStatus(405);
    }

    public function test_pages_disable_embedding_objects_and_unneeded_device_permissions(): void
    {
        $this->get('/login')->assertOk()
            ->assertHeader('Content-Security-Policy', "base-uri 'self'; object-src 'none'; frame-ancestors 'self'; form-action 'self'")
            ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/get-started')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_hsts_is_sent_only_for_secure_production_requests(): void
    {
        $this->app['env'] = 'production';
        $this->get('https://localhost/login')->assertOk()->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->get('http://localhost/login')->assertOk()->assertHeaderMissing('Strict-Transport-Security');
    }

    public function test_unsigned_payment_notifications_cannot_grant_credits(): void
    {
        Http::preventStrayRequests();
        config(['paystack.secret_key' => 'security-test-secret']);
        $this->postJson('/webhooks/paystack', ['event' => 'charge.success', 'data' => ['reference' => (string) Str::uuid(), 'amount' => 1]])
            ->assertUnauthorized();
        Http::assertNothingSent();
        $this->assertDatabaseCount('subscriptions', 0);
    }

    public function test_cross_site_mutations_require_csrf_but_provider_callbacks_require_signatures(): void
    {
        $this->app->bind(ValidateCsrfToken::class, fn ($app) => new class($app, $app['encrypter']) extends ValidateCsrfToken
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });
        $this->postJson('/get-started/draft', [])->assertStatus(419);
        $this->post('/login', ['email' => 'somebody@example.invalid', 'password' => 'forged'])->assertStatus(419);
        $this->postJson('/webhooks/paystack', ['event' => 'charge.success'])->assertUnauthorized();
    }

    public function test_php_disguised_as_docx_is_rejected(): void
    {
        $this->postJson('/get-started/import', ['file' => UploadedFile::fake()->createWithContent('resume.docx', '<?php echo "not a document";')])
            ->assertUnprocessable()->assertJsonValidationErrors('file');
    }

    public function test_docx_external_entities_and_decompression_payloads_are_rejected(): void
    {
        Http::preventStrayRequests();
        foreach ([
            '<!DOCTYPE document [<!ENTITY xxe SYSTEM "file:///C:/Windows/win.ini">]><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>&xxe;</w:t></w:r></w:p></w:body></w:document>',
            '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>'.str_repeat('A', 2000001).'</w:t></w:r></w:p></w:body></w:document>',
        ] as $xml) {
            $path = sys_get_temp_dir().'/ats-security-'.Str::uuid().'.zip';
            try {
                $archive = new \PharData($path, 0, null, \Phar::ZIP);
                $archive['[Content_Types].xml'] = '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"/>';
                $archive['word/document.xml'] = $xml;
                $archive->compressFiles(\Phar::GZ);
                unset($archive);
                $file = new UploadedFile($path, 'resume.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
                $this->postJson('/get-started/import', ['file' => $file])->assertUnprocessable()->assertJsonValidationErrors('file');
            } finally {
                unset($archive);
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
        Http::assertNothingSent();
        $this->assertDatabaseCount('resumes', 0);
    }
}
