<?php

namespace Tests\Feature;

use App\Services\CvDocumentExporter;
use Mockery\MockInterface;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class CvDownloadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }
    private function data(): array
    {
        return ['name' => 'Alex Morgan', 'template' => 'essential', 'experience' => [], 'education' => [], 'achievements' => [], 'courses' => [], 'languages' => []];
    }

    public function test_download_requires_a_name_and_supported_format(): void
    {
        $this->mock(CvDocumentExporter::class, fn (MockInterface $mock) => $mock->shouldNotReceive('create'));
        $this->postJson('/get-started/export', ['step' => 4, 'data' => ['name' => ''], 'format' => 'html'])
            ->assertUnprocessable()->assertJsonValidationErrors(['data.name', 'format']);
    }

    public function test_download_serves_each_format_as_a_private_attachment(): void
    {
        foreach (['pdf' => ['pdf', 'application/pdf'], 'word' => ['docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document']] as $format => [$extension, $type]) {
            $path = tempnam(sys_get_temp_dir(), 'cv-export-');
            file_put_contents($path, 'Generated file fixture');
            try {
                $this->mock(CvDocumentExporter::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->once()
                    ->with($this->data(), $format)->andReturn($path));
                $this->postJson('/get-started/export', ['step' => 4, 'data' => $this->data(), 'format' => $format])
                    ->assertOk()->assertDownload('alex-morgan-cv.'.$extension)->assertHeader('Content-Type', $type);
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }
    }

    public function test_generation_failure_returns_a_retry_message(): void
    {
        $this->mock(CvDocumentExporter::class, fn (MockInterface $mock) => $mock->shouldReceive('create')->once()->andThrow(new \RuntimeException('Browser unavailable')));
        $this->postJson('/get-started/export', ['step' => 4, 'data' => $this->data(), 'format' => 'pdf'])
            ->assertStatus(503)->assertJson(['message' => 'Your download could not be prepared. Please try again.']);
    }
}
