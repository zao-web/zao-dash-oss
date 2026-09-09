<?php

namespace Database\Factories;

use App\Models\SiteBuilderProject;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\SiteBuilderProject>
 */
class SiteBuilderProjectFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $domain = $this->faker->domainName();

        return [
            'domain' => $domain,
            'project_name' => ucfirst(explode('.', $domain)[0]).' Website',
            'brief' => $this->faker->paragraph(3),
            'company_type' => $this->faker->randomElement([
                SiteBuilderProject::COMPANY_ACTIVE,
                SiteBuilderProject::COMPANY_STARTUP,
                SiteBuilderProject::COMPANY_ENTERPRISE,
            ]),
            'status' => SiteBuilderProject::STATUS_CREATED,
            'environment' => SiteBuilderProject::ENV_STAGING,
            'target_hosting' => $this->faker->randomElement([
                SiteBuilderProject::HOSTING_WORDPRESS_COM,
                SiteBuilderProject::HOSTING_SELF_HOSTED,
            ]),
            'user_id' => User::factory(),
            'estimated_completion' => now()->addMinutes(40),
        ];
    }

    /**
     * Project in research phase.
     */
    public function research(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteBuilderProject::STATUS_RESEARCH,
            'started_at' => now(),
        ]);
    }

    /**
     * Project in content generation phase.
     */
    public function contentGeneration(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteBuilderProject::STATUS_CONTENT_GENERATION,
            'started_at' => now()->subMinutes(15),
        ]);
    }

    /**
     * Completed project.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteBuilderProject::STATUS_COMPLETE,
            'started_at' => now()->subMinutes(40),
            'completed_at' => now(),
            'staging_url' => 'https://'.$attributes['domain'],
        ]);
    }

    /**
     * Failed project.
     */
    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SiteBuilderProject::STATUS_FAILED,
            'started_at' => now()->subMinutes(20),
            'completed_at' => now(),
            'last_error' => $this->faker->sentence(),
        ]);
    }
}
