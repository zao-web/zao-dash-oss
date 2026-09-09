<?php

namespace Database\Factories;

use App\Models\SpinupWpServer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpinupWpServer>
 */
class SpinupWpServerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'spinup_id' => $this->faker->unique()->randomNumber(6),
            'name' => $this->faker->words(2, true).'-server',
            'provider_name' => $this->faker->randomElement(['DigitalOcean', 'Vultr', 'Hetzner', 'AWS']),
            'ubuntu_version' => $this->faker->randomElement(['22.04', '24.04']),
            'ip_address' => $this->faker->ipv4(),
            'ssh_port' => 22,
            'timezone' => 'UTC',
            'region' => $this->faker->randomElement(['nyc1', 'sfo1', 'lon1', 'ams1']),
            'size' => $this->faker->randomElement(['s-1vcpu-1gb', 's-2vcpu-2gb', 's-4vcpu-8gb']),
            'disk_space' => [
                'total' => 25 * 1073741824,
                'used' => $this->faker->numberBetween(1, 20) * 1073741824,
                'available' => $this->faker->numberBetween(5, 24) * 1073741824,
            ],
            'connection_status' => SpinupWpServer::CONNECTION_CONNECTED,
            'status' => SpinupWpServer::STATUS_PROVISIONED,
            'is_default' => false,
            'last_synced_at' => now(),
        ];
    }

    public function provisioned(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpServer::STATUS_PROVISIONED,
            'connection_status' => SpinupWpServer::CONNECTION_CONNECTED,
        ]);
    }

    public function disconnected(): static
    {
        return $this->state(fn (array $attributes) => [
            'connection_status' => SpinupWpServer::CONNECTION_DISCONNECTED,
        ]);
    }

    public function provisioning(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => SpinupWpServer::STATUS_PROVISIONING,
            'connection_status' => SpinupWpServer::CONNECTION_UNKNOWN,
        ]);
    }

    public function default(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_default' => true,
        ]);
    }
}
