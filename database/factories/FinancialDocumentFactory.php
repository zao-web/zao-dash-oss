<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\FinancialDocument>
 */
class FinancialDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $documentType = fake()->randomElement(['bank_statement', 'irs_notice', 'collections_letter', 'tax_return', 'payment_confirmation', 'other']);

        return [
            'user_id' => User::factory(),
            'document_type' => $documentType,
            'file_path' => 'documents/'.fake()->uuid().'.pdf',
            'file_name' => fake()->randomElement(['bank_statement_march.pdf', 'irs_notice_cp2000.pdf', 'tax_return_2024.pdf', 'payment_receipt.pdf']),
            'file_size' => fake()->numberBetween(50000, 5000000),
            'mime_type' => 'application/pdf',
            'extracted_data' => null,
            'extraction_confidence' => null,
            'needs_review' => true,
            'reviewed_at' => null,
            'debt_id' => null,
            'tax_obligation_id' => null,
            'effective_date' => fake()->optional()->dateTimeBetween('-1 year', 'now'),
            'response_deadline' => $documentType === 'irs_notice' ? fake()->dateTimeBetween('+1 week', '+60 days') : null,
            'irs_notice_type' => $documentType === 'irs_notice' ? fake()->randomElement(['CP2000', 'CP504', 'CP14', 'CP501', 'LT11']) : null,
        ];
    }

    public function irsNotice(): static
    {
        return $this->state(fn (array $attributes) => [
            'document_type' => 'irs_notice',
            'file_name' => 'irs_notice.pdf',
            'irs_notice_type' => fake()->randomElement(['CP2000', 'CP504', 'CP14', 'CP501']),
            'response_deadline' => fake()->dateTimeBetween('+1 week', '+60 days'),
        ]);
    }

    public function reviewed(): static
    {
        return $this->state(fn (array $attributes) => [
            'needs_review' => false,
            'reviewed_at' => fake()->dateTimeBetween('-1 month', 'now'),
        ]);
    }

    public function withExtractedData(): static
    {
        return $this->state(fn (array $attributes) => [
            'extracted_data' => [
                'amount' => fake()->randomFloat(2, 100, 50000),
                'date' => fake()->date(),
                'reference' => fake()->numerify('REF-########'),
            ],
            'extraction_confidence' => fake()->randomFloat(2, 0.7, 0.99),
        ]);
    }
}
