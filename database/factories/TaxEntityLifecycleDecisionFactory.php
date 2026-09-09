<?php

namespace Database\Factories;

use App\Models\TaxEntityLifecycleDecision;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TaxEntityLifecycleDecision>
 */
class TaxEntityLifecycleDecisionFactory extends Factory
{
    protected $model = TaxEntityLifecycleDecision::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tax_year' => 2025,
            'entity_name' => fake()->company(),
            'decision' => TaxEntityLifecycleDecision::DECISION_ACTIVE,
            'requires_final_return' => false,
            'requires_dissolution' => false,
            'notes' => null,
            'decided_at' => now(),
        ];
    }

    public function finalReturn(): static
    {
        return $this->state(fn (): array => [
            'decision' => TaxEntityLifecycleDecision::DECISION_FINAL_RETURN,
            'requires_final_return' => true,
            'requires_dissolution' => false,
        ]);
    }

    public function finalReturnAndDissolve(): static
    {
        return $this->state(fn (): array => [
            'decision' => TaxEntityLifecycleDecision::DECISION_FINAL_RETURN_AND_DISSOLVE,
            'requires_final_return' => true,
            'requires_dissolution' => true,
        ]);
    }
}
