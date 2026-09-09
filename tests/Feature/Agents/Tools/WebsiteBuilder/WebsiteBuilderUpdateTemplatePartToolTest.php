<?php

use App\Agents\Tools\WebsiteBuilder\WebsiteBuilderUpdateTemplatePartTool;
use App\Models\SpinupWpSite;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use App\Services\WordPress\WordPressMcpService;

beforeEach(function () {
    $this->user = \App\Models\User::factory()->create();
    $this->actingAs($this->user);
});

describe('WebsiteBuilderUpdateTemplatePartTool', function () {
    it('has correct metadata', function () {
        $wpService = Mockery::mock(WordPressMcpService::class);
        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        expect($tool->name())->toBe('Update Template Part')
            ->and($tool->category())->toBe('website-builder')
            ->and($tool->requiresApproval())->toBeTrue()
            ->and($tool->riskLevel())->toBe('medium');
    });

    it('has correct input schema', function () {
        $wpService = Mockery::mock(WordPressMcpService::class);
        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $schema = $tool->inputSchema();

        expect($schema['required'])->toContain('project_id', 'template_part', 'content')
            ->and($schema['properties'])->toHaveKeys(['project_id', 'template_part', 'content', 'area']);
    });

    it('returns error when project not found', function () {
        $wpService = Mockery::mock(WordPressMcpService::class);
        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $result = $tool->execute([
            'project_id' => 99999,
            'template_part' => 'footer',
            'content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain('not found');
    });

    it('returns error when no wordpress site connected', function () {
        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'building',
        ]);

        $wpService = Mockery::mock(WordPressMcpService::class);
        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $result = $tool->execute([
            'project_id' => $project->id,
            'template_part' => 'footer',
            'content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain('No WordPress site');
    });

    it('updates template part successfully via SpinupWP site', function () {
        $server = \App\Models\SpinupWpServer::factory()->provisioned()->create();

        $spinupSite = SpinupWpSite::factory()->deployed()->create([
            'spinup_server_id' => $server->id,
            'wp_admin_user' => 'admin',
            'wp_admin_password_encrypted' => encrypt('test-password'),
        ]);

        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'building',
        ]);

        $spinupSite->update(['website_project_id' => $project->id]);

        $wpService = Mockery::mock(WordPressMcpService::class);
        $wpService->shouldReceive('upsertTemplatePart')
            ->once()
            ->with(
                Mockery::type(WordPressSite::class),
                'footer',
                '<!-- wp:paragraph --><p>Custom Footer</p><!-- /wp:paragraph -->',
                'footer',
                'ollie'
            )
            ->andReturn(['id' => 'ollie//footer', 'slug' => 'footer']);

        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $result = $tool->execute([
            'project_id' => $project->id,
            'template_part' => 'footer',
            'content' => '<!-- wp:paragraph --><p>Custom Footer</p><!-- /wp:paragraph -->',
        ]);

        expect($result['success'])->toBeTrue()
            ->and($result['template_part'])->toBe('footer')
            ->and($result['area'])->toBe('footer');
    });

    it('stores template part in project', function () {
        $server = \App\Models\SpinupWpServer::factory()->provisioned()->create();

        $spinupSite = SpinupWpSite::factory()->deployed()->create([
            'spinup_server_id' => $server->id,
            'wp_admin_user' => 'admin',
            'wp_admin_password_encrypted' => encrypt('test-password'),
        ]);

        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'building',
        ]);

        $spinupSite->update(['website_project_id' => $project->id]);

        $wpService = Mockery::mock(WordPressMcpService::class);
        $wpService->shouldReceive('upsertTemplatePart')
            ->andReturn(['id' => 'ollie//header', 'slug' => 'header']);

        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $content = '<!-- wp:site-title /-->';
        $tool->execute([
            'project_id' => $project->id,
            'template_part' => 'header',
            'content' => $content,
        ]);

        $project->refresh();
        expect($project->template_parts)->toHaveKey('header')
            ->and($project->template_parts['header']['content'])->toBe($content);
    });

    it('infers area from template part name', function () {
        $server = \App\Models\SpinupWpServer::factory()->provisioned()->create();

        $spinupSite = SpinupWpSite::factory()->deployed()->create([
            'spinup_server_id' => $server->id,
            'wp_admin_user' => 'admin',
            'wp_admin_password_encrypted' => encrypt('test-password'),
        ]);

        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'building',
        ]);

        $spinupSite->update(['website_project_id' => $project->id]);

        $wpService = Mockery::mock(WordPressMcpService::class);
        $wpService->shouldReceive('upsertTemplatePart')
            ->withArgs(function ($site, $slug, $content, $area) {
                return $area === 'header';
            })
            ->andReturn(['id' => 'ollie//header-dark', 'slug' => 'header-dark']);

        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $result = $tool->execute([
            'project_id' => $project->id,
            'template_part' => 'header-dark',
            'content' => '<!-- wp:site-title /-->',
        ]);

        expect($result['area'])->toBe('header');
    });

    it('handles api errors gracefully', function () {
        $server = \App\Models\SpinupWpServer::factory()->provisioned()->create();

        $spinupSite = SpinupWpSite::factory()->deployed()->create([
            'spinup_server_id' => $server->id,
            'wp_admin_user' => 'admin',
            'wp_admin_password_encrypted' => encrypt('test-password'),
        ]);

        $project = WebsiteProject::factory()->create([
            'user_id' => $this->user->id,
            'status' => 'building',
        ]);

        $spinupSite->update(['website_project_id' => $project->id]);

        $wpService = Mockery::mock(WordPressMcpService::class);
        $wpService->shouldReceive('upsertTemplatePart')
            ->andThrow(new \Exception('401 Unauthorized'));

        $tool = new WebsiteBuilderUpdateTemplatePartTool($wpService);

        $result = $tool->execute([
            'project_id' => $project->id,
            'template_part' => 'footer',
            'content' => '<!-- wp:paragraph --><p>Test</p><!-- /wp:paragraph -->',
        ]);

        expect($result['success'])->toBeFalse()
            ->and($result['error'])->toContain('Unauthorized')
            ->and($result['suggestion'])->toContain('credentials');
    });
});
