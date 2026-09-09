<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class SeoContentWriterAgentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        \App\Models\Agent::updateOrCreate(
            ['slug' => 'seo-content-writer'],
            [
                'name' => 'SEO Content Writer',
                'description' => 'Generates individual SEO content pieces based on orchestrator strategy. Worker agent that creates ONE high-quality page at a time using proprietary data.',
                'skill_file' => 'skills/seo-content-writer/SKILL.md',
                'status' => 'active',
                'execution_mode' => 'cli', // Use CLI for content generation
                'model' => 'sonnet',
                'requires_approval' => false, // Auto-execute when dispatched by orchestrator
                'max_budget_usd' => 5.00, // $5 max per content piece
                'is_dynamic' => true, // Not a registered PHP agent
            ]
        );

        $this->command->info('SEO Content Writer agent created/updated successfully.');
    }
}
