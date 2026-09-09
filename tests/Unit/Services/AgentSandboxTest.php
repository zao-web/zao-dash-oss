<?php

namespace Tests\Unit\Services;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Services\AgentSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AgentSandboxTest extends TestCase
{
    use RefreshDatabase;

    protected AgentSandbox $sandbox;

    protected string $basePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->basePath = storage_path('app/test-agent-workspaces');
        config(['agents.sandbox.base_path' => $this->basePath]);
        $this->sandbox = new AgentSandbox;
    }

    protected function tearDown(): void
    {
        if (File::exists($this->basePath)) {
            File::deleteDirectory($this->basePath);
        }
        parent::tearDown();
    }

    /** @test */
    public function it_creates_sandbox()
    {
        $run = AgentRun::factory()->create();

        $path = $this->sandbox->create($run);

        $this->assertTrue(File::isDirectory($path));
        $this->assertTrue(File::isDirectory($path.'/workspace'));
        $this->assertTrue(File::isDirectory($path.'/output'));
        $this->assertTrue(File::isDirectory($path.'/temp'));
        $this->assertTrue(File::isDirectory($path.'/logs'));
        $this->assertTrue(File::exists($path.'/.sandbox-manifest.json'));
        $this->assertTrue(File::exists($path.'/.gitignore'));
    }

    /** @test */
    public function it_creates_manifest_with_correct_data()
    {
        $agent = Agent::factory()->create(['slug' => 'test-agent']);
        $run = AgentRun::factory()->create(['agent_id' => $agent->id]);

        $path = $this->sandbox->create($run, $agent);

        $manifest = json_decode(File::get($path.'/.sandbox-manifest.json'), true);

        $this->assertEquals($run->id, $manifest['run_id']);
        $this->assertEquals($agent->id, $manifest['agent_id']);
        $this->assertIsArray($manifest['allowed_domains']);
        $this->assertIsArray($manifest['blocked_patterns']);
    }

    /** @test */
    public function it_gets_sandbox_path()
    {
        $run = AgentRun::factory()->create();

        $expected = $this->basePath.'/'.$run->id;
        $actual = $this->sandbox->getPath($run);

        $this->assertEquals($expected, $actual);
    }

    /** @test */
    public function it_checks_sandbox_exists()
    {
        $run = AgentRun::factory()->create();

        $this->assertFalse($this->sandbox->exists($run));

        $this->sandbox->create($run);

        $this->assertTrue($this->sandbox->exists($run));
    }

    /** @test */
    public function it_validates_path_within_sandbox()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        // Allowed: within sandbox
        $this->assertTrue(
            $this->sandbox->isPathAllowed($sandboxPath, $sandboxPath.'/workspace/file.txt')
        );

        // Not allowed: outside sandbox
        $this->assertFalse(
            $this->sandbox->isPathAllowed($sandboxPath, '/etc/passwd')
        );

        // Not allowed: parent directory traversal
        $this->assertFalse(
            $this->sandbox->isPathAllowed($sandboxPath, $sandboxPath.'/../other')
        );
    }

    /** @test */
    public function it_blocks_sensitive_file_patterns()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        $blockedFiles = [
            '.env',
            '.env.local',
            'credentials.json',
            'secret.txt',
            'key.pem',
            'password.txt',
            '.git/config',
        ];

        foreach ($blockedFiles as $file) {
            $this->assertFalse(
                $this->sandbox->isPathAllowed($sandboxPath, $sandboxPath.'/workspace/'.$file),
                "File {$file} should be blocked"
            );
        }
    }

    /** @test */
    public function it_validates_write_operations()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        // Allowed: write to output
        $this->assertTrue(
            $this->sandbox->validateWrite($sandboxPath, $sandboxPath.'/output/file.txt', 1000)
        );

        // Allowed: write to temp
        $this->assertTrue(
            $this->sandbox->validateWrite($sandboxPath, $sandboxPath.'/temp/file.txt', 1000)
        );

        // Not allowed: write to workspace
        $this->assertFalse(
            $this->sandbox->validateWrite($sandboxPath, $sandboxPath.'/workspace/file.txt', 1000)
        );
    }

    /** @test */
    public function it_enforces_file_size_limits()
    {
        config(['agents.sandbox.max_file_size_mb' => 10]);
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        // Allowed: under limit
        $this->assertTrue(
            $this->sandbox->validateWrite($sandboxPath, $sandboxPath.'/output/small.txt', 5 * 1024 * 1024)
        );

        // Not allowed: over limit
        $this->assertFalse(
            $this->sandbox->validateWrite($sandboxPath, $sandboxPath.'/output/large.txt', 15 * 1024 * 1024)
        );
    }

    /** @test */
    public function it_gets_allowed_domains()
    {
        config([
            'agents.domains._default' => ['example.com'],
            'agents.domains.test-agent' => ['api.test.com'],
        ]);

        $agent = Agent::factory()->create(['slug' => 'test-agent']);

        $domains = $this->sandbox->getAllowedDomains($agent);

        $this->assertContains('api.anthropic.com', $domains);
        $this->assertContains('example.com', $domains);
        $this->assertContains('api.test.com', $domains);
    }

    /** @test */
    public function it_validates_allowed_urls()
    {
        config(['agents.domains._default' => ['api.example.com']]);

        $agent = Agent::factory()->create();

        // Allowed
        $this->assertTrue(
            $this->sandbox->isUrlAllowed('https://api.example.com/endpoint', $agent)
        );

        $this->assertTrue(
            $this->sandbox->isUrlAllowed('https://sub.api.example.com/endpoint', $agent)
        );

        // Not allowed
        $this->assertFalse(
            $this->sandbox->isUrlAllowed('https://evil.com/endpoint', $agent)
        );

        $this->assertFalse(
            $this->sandbox->isUrlAllowed('https://example.com/endpoint', $agent)
        );
    }

    /** @test */
    public function it_validates_anthropic_domain_by_default()
    {
        $agent = Agent::factory()->create();

        $this->assertTrue(
            $this->sandbox->isUrlAllowed('https://api.anthropic.com/v1/messages', $agent)
        );
    }

    /** @test */
    public function it_makes_http_requests_through_sandbox()
    {
        config(['agents.domains._default' => ['httpbin.org']]);

        $agent = Agent::factory()->create();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Domain not allowed');

        $this->sandbox->httpGet('https://evil.com', $agent);
    }

    /** @test */
    public function it_builds_sandbox_environment()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        $env = $this->sandbox->getEnvironment($run, ['API_KEY' => 'test']);

        $this->assertEquals($sandboxPath, $env['SANDBOX_PATH']);
        $this->assertEquals($sandboxPath.'/workspace', $env['SANDBOX_WORKSPACE']);
        $this->assertEquals($sandboxPath.'/output', $env['SANDBOX_OUTPUT']);
        $this->assertEquals($sandboxPath.'/temp', $env['SANDBOX_TEMP']);
        $this->assertEquals($sandboxPath, $env['HOME']);
        $this->assertEquals('test', $env['API_KEY']);
        $this->assertEquals('', $env['AWS_ACCESS_KEY_ID']);
        $this->assertEquals('', $env['GITHUB_TOKEN']);
    }

    /** @test */
    public function it_copies_files_into_sandbox()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        // Create source file
        $sourceFile = storage_path('app/test-source.txt');
        File::put($sourceFile, 'test content');

        $destPath = $this->sandbox->copyIn($run, $sourceFile);

        $this->assertTrue(File::exists($destPath));
        $this->assertEquals('test content', File::get($destPath));
        $this->assertStringContainsString('/workspace/test-source.txt', $destPath);

        File::delete($sourceFile);
    }

    /** @test */
    public function it_prevents_copying_blocked_files()
    {
        $run = AgentRun::factory()->create();
        $this->sandbox->create($run);

        $sourceFile = storage_path('app/.env');
        File::put($sourceFile, 'SECRET=123');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot copy blocked file pattern');

        $this->sandbox->copyIn($run, $sourceFile);

        File::delete($sourceFile);
    }

    /** @test */
    public function it_gets_output_files()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        File::put($sandboxPath.'/output/result1.txt', 'result 1');
        File::put($sandboxPath.'/output/result2.txt', 'result 2');

        $files = $this->sandbox->getOutputFiles($run);

        $this->assertCount(2, $files);
    }

    /** @test */
    public function it_gets_logs()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        File::put($sandboxPath.'/logs/agent.log', 'log content');

        $logs = $this->sandbox->getLogs($run);

        $this->assertEquals('log content', $logs);
    }

    /** @test */
    public function it_cleans_up_sandbox_preserving_output()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        File::put($sandboxPath.'/workspace/work.txt', 'work');
        File::put($sandboxPath.'/output/result.txt', 'result');
        File::put($sandboxPath.'/temp/tmp.txt', 'temp');

        $this->sandbox->cleanup($run, true);

        $this->assertFalse(File::exists($sandboxPath.'/workspace'));
        $this->assertFalse(File::exists($sandboxPath.'/temp'));
        $this->assertTrue(File::exists($sandboxPath.'/output/result.txt'));
    }

    /** @test */
    public function it_cleans_up_sandbox_without_preserving_output()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        File::put($sandboxPath.'/output/result.txt', 'result');

        $this->sandbox->cleanup($run, false);

        $this->assertFalse(File::exists($sandboxPath));
    }

    /** @test */
    public function it_archives_output_before_cleanup()
    {
        $run = AgentRun::factory()->create();
        $sandboxPath = $this->sandbox->create($run);

        File::put($sandboxPath.'/output/result.txt', 'important result');

        $this->sandbox->cleanup($run, true);

        $archivePath = storage_path("app/agent-archives/{$run->id}/result.txt");
        $this->assertTrue(File::exists($archivePath));
        $this->assertEquals('important result', File::get($archivePath));
    }

    /** @test */
    public function it_cleans_up_old_sandboxes()
    {
        config(['agents.sandbox.cleanup_after_hours' => 24]);

        $oldRun = AgentRun::factory()->create();
        $oldPath = $this->sandbox->create($oldRun);
        $manifest = json_decode(File::get($oldPath.'/.sandbox-manifest.json'), true);
        $manifest['created_at'] = now()->subHours(30)->toIso8601String();
        File::put($oldPath.'/.sandbox-manifest.json', json_encode($manifest));

        $newRun = AgentRun::factory()->create();
        $newPath = $this->sandbox->create($newRun);

        $count = $this->sandbox->cleanupOld();

        $this->assertEquals(1, $count);
        $this->assertFalse(File::exists($oldPath));
        $this->assertTrue(File::exists($newPath));
    }

    /** @test */
    public function it_gets_sandbox_statistics()
    {
        $run1 = AgentRun::factory()->create();
        $run2 = AgentRun::factory()->create();

        $this->sandbox->create($run1);
        $this->sandbox->create($run2);

        $stats = $this->sandbox->getStats();

        $this->assertEquals(2, $stats['total_sandboxes']);
        $this->assertArrayHasKey('total_size_mb', $stats);
        $this->assertEquals($this->basePath, $stats['base_path']);
    }
}
