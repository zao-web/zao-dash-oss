<?php

namespace Database\Seeders;

use App\Models\AgentTemplate;
use Illuminate\Database\Seeder;

/**
 * Seeds agent templates following agentic workflow best practices.
 *
 * Each template encodes:
 * - Single responsibility (one primary tool)
 * - External prompts (system_prompt_template)
 * - Explicit config schema for validation
 */
class AgentTemplateSeeder extends Seeder
{
    public function run(): void
    {
        $templates = [
            [
                'name' => 'Meeting Parser',
                'slug' => 'meeting-parser',
                'description' => 'Parse meeting transcripts into structured action items and summaries.',
                'category' => 'productivity',
                'default_model' => 'sonnet',
                'default_budget_usd' => 2.00,
                'default_requires_approval' => false,
                'default_tools' => ['api_calls'],
                'system_prompt_template' => <<<'PROMPT'
You are a meeting parser agent. Your job is to analyze meeting transcripts and extract:
1. Key decisions made
2. Action items with assignees
3. Follow-up questions
4. Summary of discussion

Output format: JSON with keys: decisions, action_items, follow_ups, summary

{{additional_instructions}}
PROMPT,
                'config_schema' => [
                    'additional_instructions' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Additional parsing instructions',
                    ],
                ],
            ],
            [
                'name' => 'Content Writer',
                'slug' => 'content-writer',
                'description' => 'Generate blog posts, case studies, and marketing content.',
                'category' => 'content',
                'default_model' => 'sonnet',
                'default_budget_usd' => 5.00,
                'default_requires_approval' => true,
                'default_tools' => ['web_search'],
                'system_prompt_template' => <<<'PROMPT'
You are a content writer for {{company_name}}. Your task is to create engaging,
professional content that aligns with the brand voice.

Content type: {{content_type}}
Target audience: {{target_audience}}
Tone: {{tone}}

Research thoroughly before writing. Cite sources where appropriate.
PROMPT,
                'config_schema' => [
                    'company_name' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Company or brand name',
                    ],
                    'content_type' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Type of content (blog_post, case_study, whitepaper)',
                    ],
                    'target_audience' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Target audience description',
                    ],
                    'tone' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Writing tone (professional, casual, technical)',
                    ],
                ],
            ],
            [
                'name' => 'Code Reviewer',
                'slug' => 'code-reviewer',
                'description' => 'Review code for bugs, security issues, and best practices.',
                'category' => 'development',
                'default_model' => 'sonnet',
                'default_budget_usd' => 3.00,
                'default_requires_approval' => false,
                'default_tools' => ['code_exec'],
                'system_prompt_template' => <<<'PROMPT'
You are a code reviewer. Analyze the provided code for:
1. Bugs and logic errors
2. Security vulnerabilities (OWASP top 10)
3. Performance issues
4. Code style and best practices
5. Test coverage gaps

Language: {{language}}
Framework: {{framework}}

Provide specific, actionable feedback with line references.
PROMPT,
                'config_schema' => [
                    'language' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Programming language',
                    ],
                    'framework' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Framework being used',
                    ],
                ],
            ],
            [
                'name' => 'Email Drafter',
                'slug' => 'email-drafter',
                'description' => 'Draft professional emails for client communication.',
                'category' => 'communication',
                'default_model' => 'sonnet',
                'default_budget_usd' => 1.00,
                'default_requires_approval' => true,
                'default_tools' => [],
                'system_prompt_template' => <<<'PROMPT'
You are an email drafting assistant. Create professional, clear emails.

Sender: {{sender_name}} ({{sender_role}})
Recipient context: {{recipient_context}}
Email purpose: {{purpose}}
Tone: {{tone}}

Keep emails concise. Include clear call-to-action when appropriate.
Do NOT send - only draft for human review.
PROMPT,
                'config_schema' => [
                    'sender_name' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Name of the sender',
                    ],
                    'sender_role' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Role/title of sender',
                    ],
                    'recipient_context' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Context about the recipient',
                    ],
                    'purpose' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Purpose of the email',
                    ],
                    'tone' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Desired tone',
                    ],
                ],
            ],
            [
                'name' => 'Data Analyzer',
                'slug' => 'data-analyzer',
                'description' => 'Analyze datasets and generate insights with visualizations.',
                'category' => 'analytics',
                'default_model' => 'sonnet',
                'default_budget_usd' => 5.00,
                'default_requires_approval' => false,
                'default_tools' => ['code_exec'],
                'system_prompt_template' => <<<'PROMPT'
You are a data analyst. Analyze the provided data to:
1. Identify trends and patterns
2. Calculate key metrics
3. Generate summary statistics
4. Suggest visualizations
5. Provide actionable insights

Data format: {{data_format}}
Analysis focus: {{focus_areas}}

Output structured JSON with findings and recommendations.
PROMPT,
                'config_schema' => [
                    'data_format' => [
                        'type' => 'string',
                        'required' => true,
                        'description' => 'Format of input data (csv, json, sql)',
                    ],
                    'focus_areas' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Specific areas to analyze',
                    ],
                ],
            ],
            [
                'name' => 'Task Planner',
                'slug' => 'task-planner',
                'description' => 'Break down projects into actionable tasks with estimates.',
                'category' => 'productivity',
                'default_model' => 'sonnet',
                'default_budget_usd' => 2.00,
                'default_requires_approval' => false,
                'default_tools' => [],
                'system_prompt_template' => <<<'PROMPT'
You are a project planning assistant. Given a project description:
1. Break it into discrete tasks
2. Identify dependencies
3. Suggest priority order
4. Flag risks and blockers

Project type: {{project_type}}
Team size: {{team_size}}

Output JSON with: tasks[], dependencies[], priorities[], risks[]
PROMPT,
                'config_schema' => [
                    'project_type' => [
                        'type' => 'string',
                        'required' => false,
                        'description' => 'Type of project',
                    ],
                    'team_size' => [
                        'type' => 'integer',
                        'required' => false,
                        'description' => 'Number of team members',
                    ],
                ],
            ],
        ];

        foreach ($templates as $template) {
            AgentTemplate::updateOrCreate(
                ['slug' => $template['slug']],
                $template
            );
        }
    }
}
