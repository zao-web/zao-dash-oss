<?php

namespace Database\Factories;

use App\Models\RfpLearningInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\RfpLearningInsight>
 */
class RfpLearningInsightFactory extends Factory
{
    protected $model = RfpLearningInsight::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'insight_type' => fake()->randomElement(['win_pattern', 'loss_pattern', 'pricing_insight']),
            'title' => fake()->sentence(4),
            'description' => fake()->paragraph(),
            'confidence' => fake()->randomFloat(2, 0.3, 0.95),
            'impact_area' => fake()->randomElement(['pricing', 'content', 'targeting', 'process']),
            'is_active' => true,
        ];
    }
}
