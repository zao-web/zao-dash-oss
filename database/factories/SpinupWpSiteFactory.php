<?php

namespace Database\Factories;

use App\Models\SpinupWpServer;
use App\Models\SpinupWpSite;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpinupWpSite>
 */
class SpinupWpSiteFactory extends Factory
{
    public function definition(): array
    {
        $domain = $this->faker->unique()->domainName();

        return [
            'spinup_id' => $this->faker->unique()->randomNumber(6),
            'spinup_server_id' => SpinupWpServer::factory(),
            'domain' => $domain,
            'site_user' => str_replace('.', '', explode('.', $domain)[0]),
            'php_version' => '8.3',
            'public_folder' => '/',
            'is_wordpress' => true,
            'page_cache_enabled' => true,
            'https_enabled' => true,
            'basic_auth_enabled' => false,
            'status' => SpinupWpSite::STATUS_DEPLOYED,
            'wp_admin_user' => 'admin',
            'wp_admin_email' => 'admin@'.$domain,
            'provisioned_at' => now(),
            'last_synced_at' => now(),
        ];
    }

    public function deployed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpSite::STATUS_DEPLOYED,
            'provisioned_at' => now(),
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpSite::STATUS_PROVISIONING,
            'provision_event_id' => $this->faker->randomNumber(5),
            'provisioned_at' => null,
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpSite::STATUS_PENDING,
            'provisioned_at' => null,
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpSite::STATUS_FAILED,
            'provisioned_at' => null,
        ]);
    }

    public function withGit(string $repo = 'zao-web/example-site'): static
    {
        return $this->state(fn (array $attributes) => [
            'git_config' => [
                'repo' => $repo,
                'branch' => 'main',
                'deploy_script' => 'wp theme install ollie --activate',
                'deployment_url' => 'https://api.spinupwp.app/v1/sites/123/git/deploy',
            ],
        ]);
    }

    public function withBasicAuth(string $username = 'staging'): static
    {
        return $this->state(fn (array $attributes) => [
            'basic_auth_enabled' => true,
            'basic_auth_username' => $username,
        ]);
    }
}
