<?php

namespace App\Mcp\Servers;

use Laravel\Mcp\Server;

class ZaoWebServer extends Server
{
    protected string $name = 'Zao Web & Content';

    public int $defaultPaginationLength = 50;

    protected string $version = '1.0.0';

    protected string $instructions = <<<'MARKDOWN'
        # Zao Web & Content MCP Server

        Website builder, WordPress management, SEO content, Gravity Forms, and Meta campaigns.

        ## Website Builder
        - `list-website-projects` - List all website builder projects
        - `get-website-project` - Get website project details and progress
        - `create-website-project` - Create a new website project
        - `attach-website-project-assets` - Attach images, PDFs, or other files
        - `trigger-website-build` - Start building a website project
        - `update-website-project` - Update status, domain, or URLs

        ## WordPress
        - `generate-wp-app-password` - Generate WordPress app password via SSH
        - `wp-search-replace` - Run WP-CLI search-replace on a SpinupWP site
        - `wp-cache-flush` - Flush WordPress and nginx caches
        - `wp-update-page` - Update or create a WordPress page via SSH/WP-CLI
        - `wp-update-template-part` - Update FSE template parts (header, footer, sidebar)
        - `wp-list-template-parts` - List available FSE template parts

        ## SEO Content
        - `list-seo-pages` - List SEO pages with filtering
        - `dispatch-seo-content-job` - Queue a background job to generate SEO content
        - `validate-seo-content` - Validate SEO content against playbook rules
        - `validate-seo-quality-gate` - Run quality gate validation for publishing readiness
        - `generate-seo-featured-image` - Generate featured image using Gemini AI or Unsplash
        - `apply-seo-cta` - Apply playbook-specific CTA with UTM tracking
        - `generate-seo-schema` - Generate JSON-LD structured data
        - `get-seo-attribution` - Get lead attribution data
        - `delete-seo-page` - Delete an SEO page

        ## Other
        - `gf-list-forms` - List all Gravity Forms
        - `gf-create-form` - Create a new form with fields
        - `create-meta-campaign` - Create a Meta ad campaign with AI-generated creatives
    MARKDOWN;

    protected array $tools = [
        \App\Mcp\Tools\ListWebsiteProjectsTool::class,
        \App\Mcp\Tools\GetWebsiteProjectTool::class,
        \App\Mcp\Tools\CreateWebsiteProjectTool::class,
        \App\Mcp\Tools\AttachWebsiteProjectAssetsTool::class,
        \App\Mcp\Tools\TriggerWebsiteBuildTool::class,
        \App\Mcp\Tools\UpdateWebsiteProjectTool::class,
        \App\Mcp\Tools\GenerateWpAppPasswordTool::class,
        \App\Mcp\Tools\WpSearchReplaceTool::class,
        \App\Mcp\Tools\WpCacheFlushTool::class,
        \App\Mcp\Tools\WpUpdatePageTool::class,
        \App\Mcp\Tools\WpUpdateTemplatePartTool::class,
        \App\Mcp\Tools\WpListTemplatePartsTool::class,
        \App\Mcp\Tools\GfListFormsTool::class,
        \App\Mcp\Tools\GfCreateFormTool::class,
        \App\Mcp\Tools\CreateMetaCampaignTool::class,
        \App\Mcp\Tools\ListSeoPagesTool::class,
        \App\Mcp\Tools\DispatchSeoContentJobTool::class,
        \App\Mcp\Tools\ValidateSeoContentTool::class,
        \App\Mcp\Tools\ValidateSeoQualityGateTool::class,
        \App\Mcp\Tools\GenerateSeoFeaturedImageMcpTool::class,
        \App\Mcp\Tools\ApplySeoCtaTool::class,
        \App\Mcp\Tools\GenerateSeoSchemaTool::class,
        \App\Mcp\Tools\GetSeoAttributionTool::class,
        \App\Mcp\Tools\DeleteSeoPageTool::class,
    ];
}
