<?php

namespace Database\Factories;

use App\Models\AgentTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class AgentTemplateFactory extends Factory
{
    protected $model = AgentTemplate::class;

    public function definition(): array
    {
        $name = fake()->words(3, true);
        $categories = ['automation', 'content', 'development', 'communication', 'analysis'];

        return [
            'name' => $name,
            'slug' => Str::slug($name).'-'.Str::random(4),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement($categories),
            'default_model' => fake()->randomElement(['opus', 'sonnet', 'haiku']),
            'default_budget_usd' => fake()->randomFloat(2, 1, 50),
            'default_requires_approval' => fake()->boolean(),
            'default_tools' => fake()->randomElements(['web_search', 'code_exec', 'file_ops', 'api_calls'], rand(1, 3)),
            'system_prompt_template' => fake()->paragraph(),
            'config_schema' => null,
            'is_public' => true,
            'usage_count' => fake()->numberBetween(0, 100),
        ];
    }

    public function featured(): static
    {
        // Note: is_featured column doesn't exist in migration
        // This is a placeholder for future implementation
        return $this->state(fn (array $attributes) => [
            'usage_count' => fake()->numberBetween(100, 500),
        ]);
    }

    public function inCategory(string $category): static
    {
        return $this->state(fn (array $attributes) => [
            'category' => $category,
        ]);
    }
}
