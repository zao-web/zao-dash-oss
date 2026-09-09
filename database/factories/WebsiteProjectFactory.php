<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\WebsiteProject;
use App\Models\WordPressSite;
use Illuminate\Database\Eloquent\Factories\Factory;

class WebsiteProjectFactory extends Factory
{
    protected $model = WebsiteProject::class;

    public function definition(): array
    {
        $name = fake()->company();

        return [
            'name' => $name,
            'user_id' => User::factory(),
            'project_type' => fake()->randomElement([
                WebsiteProject::TYPE_AUTONOMOUS,
                WebsiteProject::TYPE_GUIDED,
                WebsiteProject::TYPE_MIGRATION,
                WebsiteProject::TYPE_REDESIGN,
            ]),
            'source_type' => fake()->randomElement([
                WebsiteProject::SOURCE_DOMAIN,
                WebsiteProject::SOURCE_BRIEF,
                WebsiteProject::SOURCE_URL,
                WebsiteProject::SOURCE_GITHUB,
            ]),
            'source_data' => [
                'brief' => fake()->paragraphs(3, true),
                'requirements' => fake()->sentences(5),
            ],
            'domain' => fake()->domainName(),
            'hosting_type' => fake()->randomElement([
                WebsiteProject::HOSTING_WORDPRESS_COM,
                WebsiteProject::HOSTING_SELF_HOSTED,
                WebsiteProject::HOSTING_EXISTING_SITE,
            ]),
            'environment' => WebsiteProject::ENV_STAGING,
            'status' => WebsiteProject::STATUS_CREATED,
            'overall_progress' => 0,
            'phase_progress' => [],
            'design_config' => [
                'colors' => [
                    'primary' => fake()->hexColor(),
                    'secondary' => fake()->hexColor(),
                    'accent' => fake()->hexColor(),
                ],
                'typography' => [
                    'heading_font' => 'primary',
                    'body_font' => 'system',
                ],
            ],
            'pages' => ['home', 'about', 'services', 'contact'],
            'agent_runs' => [],
            'retry_count' => 0,
            'budget_allocated' => fake()->randomFloat(2, 10, 100),
            'cost_incurred' => 0,
        ];
    }

    public function autonomous(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_type' => WebsiteProject::TYPE_AUTONOMOUS,
            'source_type' => WebsiteProject::SOURCE_DOMAIN,
        ]);
    }

    public function guided(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_type' => WebsiteProject::TYPE_GUIDED,
            'source_type' => WebsiteProject::SOURCE_BRIEF,
            'patterns_selected' => [
                'home' => ['hero-simple', 'features-grid', 'cta-centered'],
                'about' => ['hero-team', 'team-grid'],
                'services' => ['features-list', 'pricing-table'],
            ],
        ]);
    }

    public function migration(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_type' => WebsiteProject::TYPE_MIGRATION,
            'source_type' => WebsiteProject::SOURCE_URL,
            'site_analysis' => [
                'platform' => ['type' => 'WordPress', 'version' => '6.4'],
                'pages' => ['home', 'about', 'blog', 'contact'],
                'post_count' => fake()->numberBetween(10, 100),
            ],
        ]);
    }

    public function redesign(): static
    {
        return $this->state(fn (array $attributes) => [
            'project_type' => WebsiteProject::TYPE_REDESIGN,
            'source_type' => WebsiteProject::SOURCE_URL,
        ]);
    }

    public function analyzing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_ANALYZING,
            'overall_progress' => 15,
            'started_at' => now()->subMinutes(5),
            'estimated_completion' => now()->addMinutes(25),
        ]);
    }

    public function designing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_DESIGNING,
            'overall_progress' => 35,
            'started_at' => now()->subMinutes(10),
            'estimated_completion' => now()->addMinutes(20),
        ]);
    }

    public function building(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_BUILDING,
            'overall_progress' => 60,
            'started_at' => now()->subMinutes(15),
            'estimated_completion' => now()->addMinutes(15),
            'staging_url' => 'https://'.fake()->domainName(),
        ]);
    }

    public function reviewing(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_REVIEWING,
            'overall_progress' => 85,
            'started_at' => now()->subMinutes(25),
            'estimated_completion' => now()->addMinutes(5),
            'staging_url' => 'https://'.fake()->domainName(),
        ]);
    }

    public function complete(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_COMPLETE,
            'overall_progress' => 100,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
            'staging_url' => 'https://'.fake()->domainName(),
            'production_url' => 'https://'.$attributes['domain'],
            'environment' => WebsiteProject::ENV_PRODUCTION,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => WebsiteProject::STATUS_FAILED,
            'overall_progress' => fake()->numberBetween(10, 80),
            'started_at' => now()->subMinutes(30),
            'completed_at' => now(),
            'last_error' => fake()->sentence(),
            'retry_count' => fake()->numberBetween(1, 3),
        ]);
    }

    public function withWordPressSite(): static
    {
        return $this->state(fn (array $attributes) => [
            'wordpress_site_id' => WordPressSite::factory(),
        ]);
    }

    public function withAnalysis(): static
    {
        return $this->state(fn (array $attributes) => [
            'site_analysis' => [
                'platform' => ['type' => 'WordPress', 'version' => '6.4'],
                'sitemap' => ['pages' => fake()->numberBetween(5, 50)],
                'brand_colors' => [
                    'primary' => fake()->hexColor(),
                    'secondary' => fake()->hexColor(),
                ],
            ],
            'extracted_content' => [
                'home' => [
                    'hero' => ['headline' => fake()->sentence(), 'cta' => fake()->words(2, true)],
                    'features' => fake()->sentences(3),
                ],
            ],
        ]);
    }
}
