<?php

namespace Database\Seeders;

use App\Models\Agent;
use App\Models\AgentRun;
use App\Models\ApprovalRequest;
use App\Models\Client;
use App\Models\Lead;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\VaultSecret;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create users
        $owner = User::create([
            'name' => 'Owner User',
            'email' => 'owner@example.com',
            'password' => bcrypt('password'),
            'role' => 'owner',
        ]);

        $staff = [
            User::create(['name' => 'Sarah Chen', 'email' => 'staff1@example.com', 'password' => bcrypt('password'), 'role' => 'staff']),
            User::create(['name' => 'Marcus Webb', 'email' => 'staff2@example.com', 'password' => bcrypt('password'), 'role' => 'staff']),
            User::create(['name' => 'Elena Rodriguez', 'email' => 'staff3@example.com', 'password' => bcrypt('password'), 'role' => 'staff']),
        ];

        $seededAt = now();

        // Create clients with realistic agency data
        $clients = [
            [
                'name' => 'Meridian Capital',
                'slug' => 'meridian-capital',
                'description' => 'Fintech startup revolutionizing B2B payments',
                'health_score' => 9.2,
                'status' => 'active',
                'website' => 'https://meridiancap.io',
                'slack_channel' => '#meridian-capital',
                'seeded_at' => $seededAt,
                'contacts' => [
                    ['name' => 'David Park', 'email' => 'david@meridiancap.io', 'role' => 'CTO', 'is_primary' => true],
                    ['name' => 'Lisa Chang', 'email' => 'lisa@meridiancap.io', 'role' => 'Product Manager', 'is_primary' => false],
                ],
                'projects' => [
                    ['name' => 'Payment Dashboard Redesign', 'status' => 'active', 'type' => 'project', 'budget' => 45000],
                    ['name' => 'Mobile App MVP', 'status' => 'active', 'type' => 'project', 'budget' => 85000],
                ],
            ],
            [
                'name' => 'Bloom & Wild',
                'slug' => 'bloom-wild',
                'description' => 'Luxury floral subscription service',
                'health_score' => 8.7,
                'status' => 'active',
                'website' => 'https://bloomwild.com',
                'slack_channel' => '#bloom-wild',
                'seeded_at' => $seededAt,
                'contacts' => [
                    ['name' => 'Emma Thompson', 'email' => 'emma@bloomwild.com', 'role' => 'Head of Digital', 'is_primary' => true],
                ],
                'projects' => [
                    ['name' => 'WooCommerce Migration', 'status' => 'active', 'type' => 'project', 'budget' => 62000],
                    ['name' => 'Monthly Retainer', 'status' => 'active', 'type' => 'retainer', 'budget' => 8000],
                ],
            ],
            [
                'name' => 'Velocity Logistics',
                'slug' => 'velocity-logistics',
                'description' => 'AI-powered supply chain optimization',
                'health_score' => 7.4,
                'status' => 'active',
                'website' => 'https://velocitylogistics.co',
                'slack_channel' => '#velocity',
                'seeded_at' => $seededAt,
                'contacts' => [
                    ['name' => 'Robert Kim', 'email' => 'robert@velocitylogistics.co', 'role' => 'VP Engineering', 'is_primary' => true],
                    ['name' => 'Amanda Foster', 'email' => 'amanda@velocitylogistics.co', 'role' => 'Project Lead', 'is_primary' => false],
                ],
                'projects' => [
                    ['name' => 'Fleet Tracking Portal', 'status' => 'active', 'type' => 'project', 'budget' => 120000],
                ],
            ],
            [
                'name' => 'Nourish Kitchen',
                'slug' => 'nourish-kitchen',
                'description' => 'Farm-to-table meal delivery startup',
                'health_score' => 9.5,
                'status' => 'active',
                'website' => 'https://nourishkitchen.co',
                'slack_channel' => '#nourish',
                'seeded_at' => $seededAt,
                'contacts' => [
                    ['name' => 'Michael Torres', 'email' => 'michael@nourishkitchen.co', 'role' => 'Founder', 'is_primary' => true],
                ],
                'projects' => [
                    ['name' => 'Brand Website Launch', 'status' => 'completed', 'type' => 'project', 'budget' => 28000],
                    ['name' => 'Subscription Platform', 'status' => 'active', 'type' => 'project', 'budget' => 55000],
                ],
            ],
            [
                'name' => 'Axiom Health',
                'slug' => 'axiom-health',
                'description' => 'Telehealth platform for mental wellness',
                'health_score' => 6.8,
                'status' => 'active',
                'website' => 'https://axiomhealth.io',
                'slack_channel' => '#axiom-health',
                'seeded_at' => $seededAt,
                'contacts' => [
                    ['name' => 'Dr. Jennifer Walsh', 'email' => 'jennifer@axiomhealth.io', 'role' => 'CEO', 'is_primary' => true],
                    ['name' => 'Tom Bradley', 'email' => 'tom@axiomhealth.io', 'role' => 'Tech Lead', 'is_primary' => false],
                ],
                'projects' => [
                    ['name' => 'HIPAA Compliance Audit', 'status' => 'active', 'type' => 'project', 'budget' => 35000],
                    ['name' => 'Patient Portal V2', 'status' => 'on_hold', 'type' => 'project', 'budget' => 78000],
                ],
            ],
        ];

        foreach ($clients as $clientData) {
            $contacts = $clientData['contacts'];
            $projects = $clientData['projects'];
            unset($clientData['contacts'], $clientData['projects']);

            $client = Client::create($clientData);

            foreach ($contacts as $contact) {
                $client->contacts()->create([...$contact, 'seeded_at' => $seededAt]);
            }

            foreach ($projects as $projectData) {
                $projectData['slug'] = Str::slug($projectData['name']);
                $projectData['seeded_at'] = $seededAt;
                $project = $client->projects()->create($projectData);

                // Add milestones
                $milestones = ['Discovery & Planning', 'Design Phase', 'Development Sprint 1', 'Testing & QA', 'Launch'];
                foreach ($milestones as $i => $milestoneName) {
                    $project->milestones()->create([
                        'name' => $milestoneName,
                        'status' => $i < 2 ? 'completed' : ($i === 2 ? 'in_progress' : 'pending'),
                        'due_date' => now()->addWeeks($i + 1),
                        'seeded_at' => $seededAt,
                    ]);
                }
            }
        }

        // Create tasks
        $taskTitles = [
            ['title' => 'Implement user authentication flow', 'priority' => 'high', 'status' => 'in_progress'],
            ['title' => 'Design system color tokens update', 'priority' => 'medium', 'status' => 'completed'],
            ['title' => 'API rate limiting implementation', 'priority' => 'urgent', 'status' => 'in_progress'],
            ['title' => 'Mobile responsive navigation', 'priority' => 'high', 'status' => 'pending'],
            ['title' => 'Database query optimization', 'priority' => 'medium', 'status' => 'review'],
            ['title' => 'Stripe webhook integration', 'priority' => 'high', 'status' => 'in_progress'],
            ['title' => 'Email template designs', 'priority' => 'low', 'status' => 'pending'],
            ['title' => 'Performance audit report', 'priority' => 'medium', 'status' => 'completed'],
            ['title' => 'SSO integration with Okta', 'priority' => 'high', 'status' => 'pending'],
            ['title' => 'Analytics dashboard widgets', 'priority' => 'medium', 'status' => 'in_progress'],
        ];

        $projects = Project::all();
        foreach ($taskTitles as $i => $taskData) {
            Task::create([
                ...$taskData,
                'project_id' => $projects->random()->id,
                'assigned_to' => collect([$owner, ...$staff])->random()->id,
                'source' => ['manual', 'meeting-parser', 'agent'][array_rand(['manual', 'meeting-parser', 'agent'])],
                'due_date' => now()->addDays(rand(1, 14)),
                'seeded_at' => $seededAt,
            ]);
        }

        // Create agents
        $agents = [
            [
                'name' => 'Meeting Parser',
                'slug' => 'meeting-parser',
                'description' => 'Extracts action items, decisions, and follow-ups from meeting transcripts',
                'status' => 'active',
                'model' => 'sonnet',
                'requires_approval' => false,
                'max_budget_usd' => 50,
            ],
            [
                'name' => 'Dev Agent',
                'slug' => 'dev-agent',
                'description' => 'Implements code changes based on task specifications',
                'status' => 'active',
                'model' => 'opus',
                'requires_approval' => false,
                'max_budget_usd' => 200,
            ],
            [
                'name' => 'QA Agent',
                'slug' => 'qa-agent',
                'description' => 'Reviews code, runs tests, and validates implementations',
                'status' => 'active',
                'model' => 'sonnet',
                'requires_approval' => true,
                'max_budget_usd' => 100,
            ],
            [
                'name' => 'Client Sentiment',
                'slug' => 'client-sentiment',
                'description' => 'Analyzes communications to gauge client health and satisfaction',
                'status' => 'active',
                'model' => 'haiku',
                'requires_approval' => false,
                'max_budget_usd' => 25,
            ],
            [
                'name' => 'Invoice Analyzer',
                'slug' => 'invoice-analyzer',
                'description' => 'Generates invoice drafts based on time tracking and project scope',
                'status' => 'active',
                'model' => 'sonnet',
                'requires_approval' => true,
                'max_budget_usd' => 50,
            ],
            [
                'name' => 'Communication Agent',
                'slug' => 'communication-agent',
                'description' => 'Drafts client updates, status emails, and follow-up messages',
                'status' => 'active',
                'model' => 'sonnet',
                'requires_approval' => true,
                'max_budget_usd' => 30,
            ],
        ];

        foreach ($agents as $agentData) {
            Agent::create([...$agentData, 'seeded_at' => $seededAt]);
        }

        // Create agent runs
        $agentModels = Agent::all();
        $runStatuses = ['running', 'completed', 'completed', 'completed', 'failed', 'pending_approval'];

        $agentRunData = [
            ['agent' => 'meeting-parser', 'task' => 'Processing transcript from Meridian Capital sync call', 'status' => 'running', 'minutes_ago' => 2],
            ['agent' => 'dev-agent', 'task' => 'Implement user dashboard filters for Velocity portal', 'status' => 'completed', 'minutes_ago' => 15, 'cost' => 0.42],
            ['agent' => 'qa-agent', 'task' => 'Validate PR #142: Payment flow refactor', 'status' => 'pending_approval', 'minutes_ago' => 8],
            ['agent' => 'client-sentiment', 'task' => 'Weekly sentiment analysis for all active clients', 'status' => 'completed', 'minutes_ago' => 45, 'cost' => 0.08],
            ['agent' => 'dev-agent', 'task' => 'Fix subscription renewal edge case', 'status' => 'completed', 'minutes_ago' => 120, 'cost' => 0.67],
            ['agent' => 'communication-agent', 'task' => 'Draft status update for Axiom Health stakeholders', 'status' => 'pending_approval', 'minutes_ago' => 5],
            ['agent' => 'invoice-analyzer', 'task' => 'Generate November invoice for Bloom & Wild retainer', 'status' => 'completed', 'minutes_ago' => 180, 'cost' => 0.12],
            ['agent' => 'meeting-parser', 'task' => 'Process Nourish Kitchen kickoff meeting notes', 'status' => 'completed', 'minutes_ago' => 240, 'cost' => 0.05],
        ];

        foreach ($agentRunData as $runData) {
            $agent = $agentModels->where('slug', $runData['agent'])->first();
            $run = AgentRun::create([
                'agent_id' => $agent->id,
                'session_id' => Str::uuid(),
                'status' => $runData['status'],
                'task' => $runData['task'],
                'cost_usd' => $runData['cost'] ?? 0,
                'duration_ms' => $runData['status'] === 'running' ? null : rand(15000, 180000),
                'started_at' => now()->subMinutes($runData['minutes_ago']),
                'completed_at' => $runData['status'] === 'running' ? null : now()->subMinutes($runData['minutes_ago'] - rand(1, 5)),
                'seeded_at' => $seededAt,
            ]);

            // Create approval requests for pending_approval runs
            if ($runData['status'] === 'pending_approval') {
                ApprovalRequest::create([
                    'agent_run_id' => $run->id,
                    'action_type' => $agent->slug === 'qa-agent' ? 'deploy.staging' : 'communication.client_email',
                    'description' => $agent->slug === 'qa-agent'
                        ? 'Merge PR #142 and deploy to staging environment'
                        : 'Send status update email to Axiom Health stakeholders',
                    'payload' => ['preview' => 'Sample content would appear here...'],
                    'status' => 'pending',
                    'expires_at' => now()->addHours(24),
                ]);
            }
        }

        // Create vault secrets
        $secrets = [
            [
                'name' => 'OpenAI API Key',
                'key' => 'OPENAI_API_KEY',
                'category' => 'api_key',
                'description' => 'OpenAI - Production API key for GPT-4 and embeddings',
                'is_sensitive' => true,
            ],
            [
                'name' => 'Anthropic API Key',
                'key' => 'ANTHROPIC_API_KEY',
                'category' => 'api_key',
                'description' => 'Anthropic - Claude API access for agent operations',
                'is_sensitive' => true,
            ],
            [
                'name' => 'Stripe Secret Key',
                'key' => 'STRIPE_SECRET_KEY',
                'category' => 'api_key',
                'description' => 'Stripe - Production payment processing',
                'expires_at' => now()->addDays(15), // Expiring soon
                'is_sensitive' => true,
            ],
            [
                'name' => 'AWS Access Key',
                'key' => 'AWS_ACCESS_KEY_ID',
                'category' => 'api_key',
                'description' => 'AWS - S3 and SES access for file storage and email',
                'is_sensitive' => true,
            ],
            [
                'name' => 'Slack Bot Token',
                'key' => 'SLACK_BOT_TOKEN',
                'category' => 'oauth',
                'description' => 'Slack - Bot token for workspace integrations',
                'expires_at' => now()->addDays(10), // Expiring soon
                'is_sensitive' => false,
            ],
            [
                'name' => 'GitHub App Private Key',
                'key' => 'GITHUB_APP_PRIVATE_KEY',
                'category' => 'credential',
                'description' => 'GitHub - Private key for GitHub App authentication',
                'expires_at' => now()->addMonths(6),
                'is_sensitive' => true,
            ],
            [
                'name' => 'Notion Integration Token',
                'key' => 'NOTION_API_KEY',
                'category' => 'api_key',
                'description' => 'Notion - Internal workspace integration',
                'is_sensitive' => false,
            ],
            [
                'name' => 'Database Password (Prod)',
                'key' => 'DB_PASSWORD_PROD',
                'category' => 'credential',
                'description' => 'PostgreSQL - Production database credentials',
                'is_sensitive' => true,
            ],
        ];

        foreach ($secrets as $secretData) {
            VaultSecret::create([
                ...$secretData,
                'encrypted_value' => encrypt('sk_live_'.Str::random(32)),
                'created_by' => $owner->id,
                'allowed_agents' => ['meeting-parser', 'dev-agent', 'client-sentiment'],
                'is_active' => true,
                'seeded_at' => $seededAt,
            ]);
        }

        // Create leads for CRM pipeline
        $leads = [
            [
                'company_name' => 'TechFlow Systems',
                'contact_name' => 'Jennifer Martinez',
                'contact_email' => 'jennifer@techflowsys.com',
                'website' => 'https://techflowsys.com',
                'description' => 'Enterprise SaaS looking for custom integrations',
                'stage' => 'qualified',
                'source' => 'referral',
                'deal_value' => 75000,
                'probability' => 60,
                'expected_close_date' => now()->addDays(30),
            ],
            [
                'company_name' => 'GreenLeaf Organics',
                'contact_name' => 'Marcus Chen',
                'contact_email' => 'marcus@greenleaforg.co',
                'website' => 'https://greenleaforg.co',
                'description' => 'E-commerce platform rebuild needed',
                'stage' => 'proposal',
                'source' => 'website',
                'deal_value' => 45000,
                'probability' => 75,
                'expected_close_date' => now()->addDays(14),
            ],
            [
                'company_name' => 'Urban Analytics',
                'contact_name' => 'Sarah Kim',
                'contact_email' => 'sarah@urbananalytics.io',
                'website' => 'https://urbananalytics.io',
                'description' => 'Data visualization dashboard project',
                'stage' => 'negotiation',
                'source' => 'linkedin',
                'deal_value' => 120000,
                'probability' => 85,
                'expected_close_date' => now()->addDays(7),
            ],
            [
                'company_name' => 'Coastal Properties',
                'contact_name' => 'Mike Thompson',
                'contact_email' => 'mike@coastalprop.com',
                'website' => 'https://coastalprop.com',
                'description' => 'Property management system',
                'stage' => 'new',
                'source' => 'cold_outreach',
                'deal_value' => 35000,
                'probability' => 25,
                'expected_close_date' => now()->addDays(60),
            ],
            [
                'company_name' => 'FinEdge Capital',
                'contact_name' => 'Amanda Ross',
                'contact_email' => 'amanda@finedgecap.com',
                'website' => 'https://finedgecap.com',
                'description' => 'Investment portfolio tracking app',
                'stage' => 'qualified',
                'source' => 'conference',
                'deal_value' => 95000,
                'probability' => 50,
                'expected_close_date' => now()->addDays(45),
            ],
            [
                'company_name' => 'HealthPulse',
                'contact_name' => 'Dr. Robert Liu',
                'contact_email' => 'robert@healthpulse.med',
                'website' => 'https://healthpulse.med',
                'description' => 'Patient engagement platform',
                'stage' => 'won',
                'source' => 'referral',
                'deal_value' => 85000,
                'probability' => 100,
                'expected_close_date' => now()->subDays(5),
                'converted_at' => now()->subDays(5),
            ],
            [
                'company_name' => 'Creative Studios Inc',
                'contact_name' => 'Lisa Park',
                'contact_email' => 'lisa@creativestudiosinc.com',
                'description' => 'Portfolio website redesign',
                'stage' => 'lost',
                'source' => 'website',
                'deal_value' => 25000,
                'probability' => 0,
                'expected_close_date' => now()->subDays(10),
            ],
            [
                'company_name' => 'Quantum Logistics',
                'contact_name' => 'James Wright',
                'contact_email' => 'james@quantumlog.com',
                'website' => 'https://quantumlog.com',
                'description' => 'Fleet management system overhaul',
                'stage' => 'proposal',
                'source' => 'referral',
                'deal_value' => 150000,
                'probability' => 70,
                'expected_close_date' => now()->addDays(21),
            ],
        ];

        foreach ($leads as $leadData) {
            Lead::create([
                ...$leadData,
                'assigned_to' => collect([$owner, ...$staff])->random()->id,
                'seeded_at' => $seededAt,
            ]);
        }

        // Seed agent templates
        $this->call(AgentTemplateSeeder::class);
    }
}
