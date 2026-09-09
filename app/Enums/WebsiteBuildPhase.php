<?php

namespace App\Enums;

/**
 * Defines the phases of a website build process.
 *
 * Each phase is designed to complete within 5-10 minutes to avoid timeouts.
 * Phases are executed sequentially, with state persisted to WebsiteProject between phases.
 */
enum WebsiteBuildPhase: string
{
    case Research = 'research';
    case DesignBlueprint = 'design_blueprint';
    case ThemeGeneration = 'theme_generation';
    case PageHome = 'page_home';
    case PageAbout = 'page_about';
    case PageServices = 'page_services';
    case PageContact = 'page_contact';
    case PageCustom = 'page_custom';
    case QualityAssurance = 'qa';
    case Finalization = 'finalization';

    /**
     * Get the human-readable name for this phase.
     */
    public function label(): string
    {
        return match ($this) {
            self::Research => 'Research & Analysis',
            self::DesignBlueprint => 'Design Blueprint',
            self::ThemeGeneration => 'Theme Generation',
            self::PageHome => 'Build Homepage',
            self::PageAbout => 'Build About Page',
            self::PageServices => 'Build Services Page',
            self::PageContact => 'Build Contact Page',
            self::PageCustom => 'Build Custom Pages',
            self::QualityAssurance => 'Quality Assurance',
            self::Finalization => 'Finalization',
        };
    }

    /**
     * Get the timeout for this phase in seconds.
     */
    public function timeoutSeconds(): int
    {
        return match ($this) {
            self::Research => 600,          // 10 min - web searches, social proof
            self::DesignBlueprint => 480,   // 8 min - design decisions
            self::ThemeGeneration => 300,   // 5 min - generate theme.json
            self::PageHome => 480,          // 8 min - most complex page
            self::PageAbout => 360,         // 6 min
            self::PageServices => 420,      // 7 min
            self::PageContact => 300,       // 5 min
            self::PageCustom => 600,        // 10 min - batch of remaining pages
            self::QualityAssurance => 600,  // 10 min - comprehensive QA (perf, a11y, mobile, functional)
            self::Finalization => 180,      // 3 min
        };
    }

    /**
     * Get the next phase after this one, or null if this is the last phase.
     */
    public function next(): ?self
    {
        return match ($this) {
            self::Research => self::DesignBlueprint,
            self::DesignBlueprint => self::ThemeGeneration,
            self::ThemeGeneration => self::PageHome,
            self::PageHome => self::PageAbout,
            self::PageAbout => self::PageServices,
            self::PageServices => self::PageContact,
            self::PageContact => self::PageCustom,
            self::PageCustom => self::QualityAssurance,
            self::QualityAssurance => self::Finalization,
            self::Finalization => null,
        };
    }

    /**
     * Get the progress percentage range for this phase.
     *
     * @return array{start: int, end: int}
     */
    public function progressRange(): array
    {
        return match ($this) {
            self::Research => ['start' => 0, 'end' => 12],
            self::DesignBlueprint => ['start' => 12, 'end' => 22],
            self::ThemeGeneration => ['start' => 22, 'end' => 30],
            self::PageHome => ['start' => 30, 'end' => 45],
            self::PageAbout => ['start' => 45, 'end' => 55],
            self::PageServices => ['start' => 55, 'end' => 65],
            self::PageContact => ['start' => 65, 'end' => 72],
            self::PageCustom => ['start' => 72, 'end' => 85],
            self::QualityAssurance => ['start' => 85, 'end' => 95],
            self::Finalization => ['start' => 95, 'end' => 100],
        };
    }

    /**
     * Get the status to set on the WebsiteProject during this phase.
     */
    public function projectStatus(): string
    {
        return match ($this) {
            self::Research => 'analyzing',
            self::DesignBlueprint => 'designing',
            self::ThemeGeneration => 'designing',
            self::PageHome, self::PageAbout, self::PageServices, self::PageContact, self::PageCustom => 'building',
            self::QualityAssurance => 'reviewing',
            self::Finalization => 'deploying',
        };
    }

    /**
     * Get the standard phases for an autonomous build.
     *
     * @return array<self>
     */
    public static function autonomousBuildPhases(): array
    {
        return [
            self::Research,
            self::DesignBlueprint,
            self::ThemeGeneration,
            self::PageHome,
            self::PageAbout,
            self::PageServices,
            self::PageContact,
            self::QualityAssurance,
            self::Finalization,
        ];
    }

    /**
     * Determine phases based on project configuration.
     *
     * @param  array<string>  $pagesList  List of page slugs to build
     * @return array<self>
     */
    public static function phasesForPages(array $pagesList): array
    {
        $phases = [
            self::Research,
            self::DesignBlueprint,
            self::ThemeGeneration,
        ];

        foreach ($pagesList as $slug) {
            $phases[] = match (strtolower($slug)) {
                'home', 'homepage', 'front-page' => self::PageHome,
                'about', 'about-us' => self::PageAbout,
                'services', 'our-services' => self::PageServices,
                'contact', 'contact-us' => self::PageContact,
                default => null, // Will be handled by PageCustom
            };
        }

        // Filter nulls and add custom pages phase if needed
        $phases = array_filter($phases);
        $hasCustomPages = count(array_filter($pagesList, fn ($s) => ! in_array(strtolower($s), ['home', 'homepage', 'front-page', 'about', 'about-us', 'services', 'our-services', 'contact', 'contact-us']))) > 0;

        if ($hasCustomPages) {
            $phases[] = self::PageCustom;
        }

        $phases[] = self::QualityAssurance;
        $phases[] = self::Finalization;

        return array_values(array_unique($phases));
    }

    /**
     * Get the agent slug that handles this phase.
     */
    public function agentSlug(): string
    {
        return match ($this) {
            self::Research => 'website-builder-research',
            self::DesignBlueprint => 'website-builder-design-blueprint',
            self::ThemeGeneration => 'website-builder-theme',
            self::PageHome, self::PageAbout, self::PageServices, self::PageContact, self::PageCustom => 'website-builder-page',
            self::QualityAssurance => 'website-builder-qa',
            self::Finalization => 'website-builder-finalize',
        };
    }

    /**
     * Get the skill file path for this phase.
     */
    public function skillPath(): string
    {
        return match ($this) {
            self::Research => 'website-builder-phases/research.md',
            self::DesignBlueprint => 'website-builder-phases/design-blueprint.md',
            self::ThemeGeneration => 'website-builder-phases/theme-generation.md',
            self::PageHome, self::PageAbout, self::PageServices, self::PageContact, self::PageCustom => 'website-builder-phases/page-builder.md',
            self::QualityAssurance => 'website-builder-phases/qa.md',
            self::Finalization => 'website-builder-phases/finalization.md',
        };
    }
}
