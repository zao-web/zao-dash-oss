<?php

namespace App\Jobs;

use App\Enums\WebsiteBuildPhase;
use App\Events\WebsiteBuilderError;
use App\Events\WebsiteBuilderMessageReceived;
use App\Events\WebsiteBuilderStatusUpdated;
use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\WebsiteProject;
use App\Services\Agents\AgentExecutor;
use App\Services\WebsiteProjectAssetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ExecuteWebsiteBuildPhaseJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public array $backoff = [30, 60];

    public int $timeout;

    public function __construct(
        public WebsiteProject $project,
        public WebsiteBuildPhase $phase,
        public ?string $customPageSlug = null,
        public ?string $additionalInstructions = null,
    ) {
        $this->timeout = $phase->timeoutSeconds() + 60;
    }

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping($this->project->id))
                ->releaseAfter(60)
                ->expireAfter($this->timeout + 120),
        ];
    }

    public function handle(AgentExecutor $executor): void
    {
        $project = $this->project->fresh();

        if ($project->status === WebsiteProject::STATUS_FAILED) {
            Log::info('Skipping phase for failed project', [
                'project_id' => $project->id,
                'phase' => $this->phase->value,
            ]);

            return;
        }

        $progressRange = $this->phase->progressRange();
        $this->broadcastPhaseStart($project, $progressRange['start']);

        $agent = $this->getOrCreatePhaseAgent();

        if (! $agent) {
            $this->handlePhaseFailure($project, 'Phase agent not configured');

            return;
        }

        $prompt = $this->buildPhasePrompt($project);
        $context = $this->buildPhaseContext($project);

        $config = [
            'prompt' => $prompt,
            'timeout_seconds' => $this->phase->timeoutSeconds(),
            'context' => $context,
        ];

        try {
            $run = $executor->execute(
                agent: $agent,
                config: $config,
                invocationSource: AgentRun::SOURCE_SCHEDULED,
                invokedBy: 'system:website-builder-phase',
                triggerMetadata: [
                    'phase' => $this->phase->value,
                    'project_id' => $project->id,
                ],
                projectId: null,
                taskId: null,
            );

            if ($run->status === 'completed') {
                $this->handlePhaseSuccess($project, $run, $progressRange['end']);
            } else {
                $errorMessage = is_array($run->output) && isset($run->output['error'])
                    ? $run->output['error']
                    : 'Phase execution failed';

                $this->handlePhaseFailure($project, $errorMessage);
            }
        } catch (\Throwable $e) {
            Log::error('Phase execution exception', [
                'project_id' => $project->id,
                'phase' => $this->phase->value,
                'error' => $e->getMessage(),
            ]);

            $this->handlePhaseFailure($project, $e->getMessage());
        }
    }

    protected function getOrCreatePhaseAgent(): ?Agent
    {
        $agentSlug = $this->phase->agentSlug();

        $agent = Agent::where('slug', $agentSlug)->first();

        if ($agent) {
            return $agent;
        }

        $agent = Agent::where('slug', 'website-builder-orchestrator')->first();

        return $agent;
    }

    protected function buildPhasePrompt(WebsiteProject $project): string
    {
        $prompt = "## Phase: {$this->phase->label()}\n\n";
        $prompt .= "Project: {$project->name}\n";
        $prompt .= "Project ID: {$project->id}\n";
        $prompt .= "Domain: {$project->domain}\n\n";

        $phaseInstructions = match ($this->phase) {
            WebsiteBuildPhase::Research => $this->getResearchPrompt($project),
            WebsiteBuildPhase::DesignBlueprint => $this->getDesignBlueprintPrompt($project),
            WebsiteBuildPhase::ThemeGeneration => $this->getThemeGenerationPrompt($project),
            WebsiteBuildPhase::PageHome,
            WebsiteBuildPhase::PageAbout,
            WebsiteBuildPhase::PageServices,
            WebsiteBuildPhase::PageContact,
            WebsiteBuildPhase::PageCustom => $this->getPageBuilderPrompt($project),
            WebsiteBuildPhase::QualityAssurance => $this->getQAPrompt($project),
            WebsiteBuildPhase::Finalization => $this->getFinalizationPrompt($project),
        };

        $prompt .= $phaseInstructions;

        if ($this->additionalInstructions) {
            $prompt .= "\n\n## Additional Instructions\n{$this->additionalInstructions}";
        }

        return $prompt;
    }

    protected function getResearchPrompt(WebsiteProject $project): string
    {
        $sourceData = $project->source_data ?? [];
        $brief = $sourceData['brief'] ?? '';

        return <<<PROMPT
## Objective
Research and analyze the business to gather information for website creation.

## Tasks
1. **Domain Analysis**: Research {$project->domain} to understand the business
2. **Social Proof**: Search for Google, Yelp, and Facebook reviews
3. **Competitor Analysis**: Find 2-3 similar businesses for inspiration
4. **Brand Discovery**: Identify colors, tone, and style from existing materials

## Project Brief
{$brief}

## Required Output
After completing research, update the project using the progress API:

```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/progress" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "status": "analyzing",
    "overall_progress": 12,
    "phase": "research",
    "phase_progress": 100,
    "message": "Research complete. Found [X] reviews with [Y] star rating."
  }'
```

Save research findings using the website-builder-update-progress tool:
- Store social proof data in source_data.social_proof
- Store competitor insights in source_data.competitors
- Store brand analysis in source_data.brand_discovery

IMPORTANT: This is Phase 1 of the build. Focus ONLY on research. Do not generate themes or pages.
PROMPT;
    }

    protected function getDesignBlueprintPrompt(WebsiteProject $project): string
    {
        $research = $project->source_data['research_findings'] ?? [];
        $socialProof = $project->source_data['social_proof'] ?? [];

        $researchSummary = ! empty($research) ? json_encode($research, JSON_PRETTY_PRINT) : 'No research data yet.';
        $socialProofSummary = ! empty($socialProof) ? json_encode($socialProof, JSON_PRETTY_PRINT) : 'No social proof gathered.';

        return <<<PROMPT
## Objective
Create a comprehensive design blueprint based on research findings.

## Research Findings
{$researchSummary}

## Social Proof Data
{$socialProofSummary}

## Tasks
1. **Art Direction**: Define tone, contrast level, shape language
2. **Color Strategy**: Select primary, accent, and background colors
3. **Typography Strategy**: Choose heading/body fonts and scale
4. **Layout Rhythm**: Plan section flow, CTA spacing
5. **Pattern Recommendations**: Select Ollie patterns for each page type

## Required Output
Create design blueprint and save to project.design_config.design_blueprint using the website-builder-update-progress tool.

Structure your blueprint as:
```json
{
  "art_direction": { "tone": "...", "contrast_level": "...", "shape_language": "..." },
  "color_strategy": { "primary": "#...", "accent": "#...", "background": "#..." },
  "typography_strategy": { "heading_font": "...", "body_font": "..." },
  "layout_rhythm": { "sections_per_page": 6, "cta_spacing": "every 2-3 sections" },
  "pattern_recommendations": {
    "home": ["hero-call-to-action-buttons", "feature-boxes-with-button"],
    "about": ["hero-light", "team-members"],
    "services": ["feature-boxes-icons-dark"],
    "contact": ["contact-details"]
  }
}
```

IMPORTANT: This is Phase 2. Focus ONLY on design decisions. Do not create theme files yet.
PROMPT;
    }

    protected function getThemeGenerationPrompt(WebsiteProject $project): string
    {
        $blueprint = $project->design_config['design_blueprint'] ?? [];
        $brandColors = $project->design_config['brand_colors'] ?? [];

        $blueprintJson = ! empty($blueprint) ? json_encode($blueprint, JSON_PRETTY_PRINT) : 'No design blueprint found.';
        $brandJson = ! empty($brandColors) ? json_encode($brandColors, JSON_PRETTY_PRINT) : '';

        return <<<PROMPT
## Objective
Generate and upload theme files based on the design blueprint.

## Design Blueprint
{$blueprintJson}

## Brand Colors (if extracted)
{$brandJson}

## Tasks
1. Generate theme.json with color palette, typography, and spacing
2. Generate style.css with custom CSS variables and overrides
3. Generate functions.php with font loading and theme setup
4. Upload all files using website-builder-upload-theme-file tool

## Expected Files
- theme.json: WordPress Full Site Editing theme configuration
- style.css: Child theme styles extending Ollie
- functions.php: Theme functions for fonts and customizations

## After Upload
Update progress:
```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/progress" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "status": "designing",
    "overall_progress": 30,
    "phase": "theme",
    "phase_progress": 100,
    "message": "Theme files uploaded successfully."
  }'
```

IMPORTANT: This is Phase 3. Focus ONLY on theme generation and upload.
PROMPT;
    }

    protected function getPageBuilderPrompt(WebsiteProject $project): string
    {
        $pageSlug = $this->getPageSlugForPhase();
        $pageTitle = $this->getPageTitleForPhase();
        $blueprint = $project->design_config['design_blueprint'] ?? [];

        // Pattern recommendations can be either:
        // 1. Simple array: ['pattern1', 'pattern2']
        // 2. Nested structure: { patterns: [...], content_strategy: {...} }
        $patternConfig = $blueprint['pattern_recommendations'][$pageSlug] ?? [];
        $patterns = is_array($patternConfig) && isset($patternConfig['patterns'])
            ? $patternConfig['patterns']
            : (is_array($patternConfig) && ! isset($patternConfig['patterns']) && array_is_list($patternConfig) ? $patternConfig : []);
        $patternsStr = ! empty($patterns) ? implode(', ', $patterns) : 'Use appropriate Ollie patterns';

        $existingPages = $project->pages ?? [];
        $existingPagesJson = ! empty($existingPages) ? json_encode(array_keys($existingPages)) : '[]';

        return <<<PROMPT
## Objective
Build the {$pageTitle} page using Ollie patterns.

## Page Details
- Title: {$pageTitle}
- Slug: {$pageSlug}
- Recommended Patterns: {$patternsStr}

## Design Blueprint Pattern Recommendations
```json
{$this->formatPatternRecommendations($blueprint)}
```

## Already Built Pages
{$existingPagesJson}

## Tasks
1. Use website-builder-compose-page to create the page
2. Customize pattern content for this business
3. Include relevant testimonials from social proof
4. Ensure proper CTAs and conversion flow

## Example Tool Call
```bash
php artisan tool:execute website-builder-compose-page --params='{
  "project_id": {$project->id},
  "page_title": "{$pageTitle}",
  "page_slug": "{$pageSlug}",
  "patterns": ["ollie/hero-call-to-action-buttons", "ollie/feature-boxes-with-button"],
  "template": "page-no-title"
}'
```

## After Page Creation
Broadcast message to user:
```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/message" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "content": "Created {$pageTitle} page with [pattern names].",
    "role": "assistant"
  }'
```

IMPORTANT: Build ONLY the {$pageTitle} page in this phase.
PROMPT;
    }

    protected function getQAPrompt(WebsiteProject $project): string
    {
        $stagingUrl = $project->staging_url ?? $project->getLiveUrl() ?? 'Not yet deployed';
        $pages = $project->pages ?? [];
        $pagesBuilt = count($pages);
        $pagesList = implode(', ', array_column($pages, 'title'));

        $blueprint = $project->design_blueprint ?? [];
        $brandColors = $blueprint['color_strategy']['primary'] ?? 'Not specified';

        return <<<PROMPT
## Objective
Perform COMPREHENSIVE quality assurance on the built website. This is the final gate before launch.

## Site Details
- Staging URL: {$stagingUrl}
- Pages Built: {$pagesBuilt} ({$pagesList})
- Project ID: {$project->id}

---

## 1. PERFORMANCE TESTING (Core Web Vitals)

Use Playwright browser to load the site and evaluate performance:

### Metrics to Check
- **LCP** (Largest Contentful Paint): Target < 2.5s
- **CLS** (Cumulative Layout Shift): Target < 0.1
- **Page Load Time**: Target < 3s on desktop

### Performance Checks
- [ ] All images load quickly (check for large unoptimized images)
- [ ] No render-blocking resources causing slow first paint
- [ ] Lazy loading working on below-fold images
- [ ] Page doesn't have excessive JavaScript errors in console

---

## 2. MOBILE RESPONSIVE TESTING

Test at these viewport widths using Playwright:
- **Mobile**: 375px width
- **Tablet**: 768px width
- **Desktop**: 1440px width

### Mobile Checklist
- [ ] Navigation collapses to hamburger menu properly
- [ ] No horizontal scrolling on any page
- [ ] Text is readable without zooming (16px+ body text)
- [ ] Touch targets are large enough (44x44px minimum)
- [ ] Images scale properly, don't overflow containers
- [ ] Forms are usable on mobile
- [ ] CTAs are thumb-friendly

---

## 3. ACCESSIBILITY TESTING (WCAG AA)

### Critical Accessibility Checks
- [ ] **Color Contrast**: Text has 4.5:1 contrast ratio (check against {$brandColors})
- [ ] **Alt Text**: All images have meaningful alt text
- [ ] **Heading Hierarchy**: Proper h1 > h2 > h3 structure (only ONE h1 per page)
- [ ] **Keyboard Navigation**: Can tab through entire page
- [ ] **Focus Indicators**: Visible focus states on interactive elements
- [ ] **Link Text**: Links are descriptive (no "click here")
- [ ] **Form Labels**: All form inputs have visible labels
- [ ] **Language**: html element has lang attribute

### Screen Reader Checks
- [ ] Page has logical reading order
- [ ] Skip to main content link present (or navigation is minimal)
- [ ] Buttons and links have accessible names

---

## 4. FUNCTIONAL TESTING

### Navigation
- [ ] Logo links to homepage
- [ ] All menu links work and go to correct pages
- [ ] Mobile menu opens and closes
- [ ] Active page is highlighted in nav

### Links
- [ ] All internal links resolve (no 404s)
- [ ] CTA buttons have correct destinations
- [ ] Footer links work
- [ ] External links open in new tab (if applicable)

### Forms (if present)
- [ ] Form fields accept input
- [ ] Required field validation works
- [ ] Email format validation works
- [ ] Form submits successfully
- [ ] Success message displays

### Interactive Elements
- [ ] Accordions expand/collapse
- [ ] Tabs switch content
- [ ] Carousels/sliders work (if present)
- [ ] Modal dialogs work (if present)

---

## 5. VISUAL CONSISTENCY

### Design Review
- [ ] Typography is consistent across pages
- [ ] Colors match brand guidelines from design blueprint
- [ ] Spacing is consistent (no cramped or overly spaced sections)
- [ ] Icons are consistent style
- [ ] Buttons have consistent styling

### Visual Bugs
- [ ] No orphaned headings (single word on line)
- [ ] No overlapping elements
- [ ] No cut-off text
- [ ] No broken images (check all pages)
- [ ] No flash of unstyled content

---

## 6. SEO & META TAGS

- [ ] Each page has unique <title>
- [ ] Meta descriptions present
- [ ] Open Graph image set (for social sharing)
- [ ] Favicon displays

---

## Testing Process

1. **Open site in Playwright** at staging URL
2. **Desktop first**: Check each page at 1440px
3. **Take screenshot** of homepage (desktop)
4. **Resize to mobile** (375px): Check responsive behavior
5. **Take screenshot** of homepage (mobile)
6. **Run through all checklist items above**
7. **Document all issues found**

---

## QA Report Format

After testing, update progress with a structured report:

```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/progress" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "status": "reviewing",
    "overall_progress": 95,
    "phase": "qa",
    "phase_progress": 100,
    "message": "QA Complete - Score: [X]/100",
    "qa_results": {
      "performance": {"score": 0-100, "issues": []},
      "mobile": {"score": 0-100, "issues": []},
      "accessibility": {"score": 0-100, "issues": []},
      "functionality": {"score": 0-100, "issues": []},
      "visual": {"score": 0-100, "issues": []},
      "overall_score": 0-100,
      "blocking_issues": [],
      "recommendations": []
    }
  }'
```

---

## Pass/Fail Criteria

**PASS** (proceed to finalization):
- Performance: Page loads in < 4s
- No critical accessibility violations
- All navigation links work
- Site is usable on mobile
- No broken images

**NEEDS WORK** (flag for review but proceed):
- Minor accessibility issues (contrast, alt text)
- Performance could be better but functional
- Minor visual inconsistencies

**FAIL** (halt build, notify team):
- Site doesn't load
- Major broken functionality
- Critical accessibility violations (keyboard trap)
- Severe visual bugs making site unusable

---

IMPORTANT: 
- This is QA phase - document issues, don't fix them (unless critical)
- Be thorough but efficient - we're checking quality, not perfecting
- Take screenshots for evidence of any issues found
PROMPT;
    }

    protected function getFinalizationPrompt(WebsiteProject $project): string
    {
        $stagingUrl = $project->staging_url ?? '';

        return <<<PROMPT
## Objective
Finalize the website build and notify completion.

## Tasks
1. Verify all pages are published
2. Set homepage as front page
3. Create navigation menu (if not done)
4. Final progress update to 100%
5. Broadcast completion message

## Completion Message Template
```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/message" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "content": "**Your website is ready!**\\n\\nI built [X] pages with a custom design. Preview your site at: {$stagingUrl}\\n\\nLet me know if you would like any changes!",
    "role": "assistant"
  }'
```

## Final Status Update
```bash
curl -X POST "\${APP_URL}/api/internal/website-builder/progress" \\
  -H "Content-Type: application/json" \\
  -H "X-Agent-Token: \${AGENT_INTERNAL_TOKEN}" \\
  -d '{
    "project_id": {$project->id},
    "status": "complete",
    "overall_progress": 100,
    "message": "Website build complete!"
  }'
```

IMPORTANT: This is the final phase. Mark project as complete.
PROMPT;
    }

    protected function buildPhaseContext(WebsiteProject $project): array
    {
        $assetService = app(WebsiteProjectAssetService::class);

        return [
            'project_id' => $project->id,
            'project' => $project->toArray(),
            'phase' => $this->phase->value,
            'phase_label' => $this->phase->label(),
            'source_data' => $project->source_data ?? [],
            'design_config' => $project->design_config ?? [],
            'pages_built' => $project->pages ?? [],
            'staging_url' => $project->staging_url,
            'assets' => $assetService->getAssetsForAgent($project),
        ];
    }

    protected function broadcastPhaseStart(WebsiteProject $project, int $progress): void
    {
        $project->update([
            'status' => $this->phase->projectStatus(),
            'overall_progress' => $progress,
        ]);

        broadcast(new WebsiteBuilderMessageReceived(
            project: $project,
            content: "Starting {$this->phase->label()}...",
            role: 'assistant',
            metadata: ['phase' => $this->phase->value, 'action' => 'phase_start']
        ));

        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: $this->phase->projectStatus(),
            progress: $progress,
            phase: $this->phase->value
        ));
    }

    protected function handlePhaseSuccess(WebsiteProject $project, AgentRun $run, int $progress): void
    {
        $project = $project->fresh();

        $project->update([
            'overall_progress' => $progress,
            'phase_progress' => array_merge($project->phase_progress ?? [], [
                $this->phase->value => 100,
            ]),
        ]);

        broadcast(new WebsiteBuilderStatusUpdated(
            project: $project,
            status: $project->status,
            progress: $progress,
            phase: $this->phase->value
        ));

        Log::info('Phase completed successfully', [
            'project_id' => $project->id,
            'phase' => $this->phase->value,
            'progress' => $progress,
        ]);

        $this->scheduleNextPhase($project);
    }

    protected function handlePhaseFailure(WebsiteProject $project, string $error): void
    {
        $project->update([
            'status' => WebsiteProject::STATUS_FAILED,
            'last_error' => "Phase {$this->phase->value} failed: {$error}",
        ]);

        broadcast(new WebsiteBuilderError(
            project: $project,
            message: "Build failed during {$this->phase->label()}: {$error}"
        ));

        Log::error('Phase execution failed', [
            'project_id' => $project->id,
            'phase' => $this->phase->value,
            'error' => $error,
        ]);
    }

    protected function scheduleNextPhase(WebsiteProject $project): void
    {
        $nextPhase = $this->phase->next();

        if (! $nextPhase) {
            $project->update([
                'status' => WebsiteProject::STATUS_COMPLETE,
                'overall_progress' => 100,
                'completed_at' => now(),
            ]);

            broadcast(new WebsiteBuilderMessageReceived(
                project: $project,
                content: "Website build complete! Your site is ready at: {$project->staging_url}",
                role: 'assistant',
                metadata: ['action' => 'build_complete']
            ));

            return;
        }

        dispatch(new self(
            project: $project,
            phase: $nextPhase,
            additionalInstructions: $this->additionalInstructions
        ))->delay(now()->addSeconds(5));

        Log::info('Scheduled next phase', [
            'project_id' => $project->id,
            'current_phase' => $this->phase->value,
            'next_phase' => $nextPhase->value,
        ]);
    }

    protected function getPageSlugForPhase(): string
    {
        if ($this->customPageSlug) {
            return $this->customPageSlug;
        }

        return match ($this->phase) {
            WebsiteBuildPhase::PageHome => 'home',
            WebsiteBuildPhase::PageAbout => 'about',
            WebsiteBuildPhase::PageServices => 'services',
            WebsiteBuildPhase::PageContact => 'contact',
            default => 'page',
        };
    }

    protected function getPageTitleForPhase(): string
    {
        return match ($this->phase) {
            WebsiteBuildPhase::PageHome => 'Home',
            WebsiteBuildPhase::PageAbout => 'About',
            WebsiteBuildPhase::PageServices => 'Services',
            WebsiteBuildPhase::PageContact => 'Contact',
            default => 'Page',
        };
    }

    protected function formatPatternRecommendations(array $blueprint): string
    {
        $patterns = $blueprint['pattern_recommendations'] ?? [];

        return ! empty($patterns) ? json_encode($patterns, JSON_PRETTY_PRINT) : '{}';
    }

    public function uniqueId(): string
    {
        return "website-build-{$this->project->id}-{$this->phase->value}";
    }

    public function tags(): array
    {
        return [
            'website-builder',
            "project:{$this->project->id}",
            "phase:{$this->phase->value}",
        ];
    }

    public function failed(\Throwable $exception): void
    {
        $this->handlePhaseFailure($this->project, $exception->getMessage());
    }
}
