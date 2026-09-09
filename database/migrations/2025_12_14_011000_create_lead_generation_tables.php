<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ideal Customer Profile definitions
        Schema::create('ideal_customer_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);

            // Target criteria (JSON arrays)
            $table->json('industries')->nullable(); // ["SaaS", "E-commerce", "Healthcare"]
            $table->json('company_sizes')->nullable(); // ["10-50", "51-200", "201-500"]
            $table->json('locations')->nullable(); // ["United States", "Canada"]
            $table->json('tech_stack')->nullable(); // ["WordPress", "React", "Laravel"]
            $table->json('tools_used')->nullable(); // ["HubSpot", "Salesforce"]
            $table->json('buying_signals')->nullable(); // ["recently_funded", "hiring_developers"]
            $table->json('pain_points')->nullable(); // ["scaling_issues", "tech_debt"]

            // Scoring weights (sum to 100)
            $table->integer('weight_industry')->default(30);
            $table->integer('weight_size')->default(25);
            $table->integer('weight_tech')->default(25);
            $table->integer('weight_signals')->default(20);

            // Targets
            $table->decimal('avg_deal_value', 14, 2)->nullable();
            $table->integer('target_monthly_leads')->default(10);

            $table->timestamps();
        });

        // Pre-lead prospects
        Schema::create('prospects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('icp_id')->nullable()->constrained('ideal_customer_profiles')->nullOnDelete();

            // Company info
            $table->string('company_name');
            $table->string('company_website')->nullable();
            $table->string('company_linkedin')->nullable();
            $table->string('industry')->nullable();
            $table->string('company_size')->nullable();
            $table->string('location')->nullable();
            $table->decimal('estimated_revenue', 14, 2)->nullable();

            // Contact info
            $table->string('contact_name')->nullable();
            $table->string('contact_title')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_linkedin')->nullable();
            $table->string('contact_phone')->nullable();

            // Enrichment data (JSON)
            $table->json('tech_stack')->nullable();
            $table->json('signals')->nullable();
            $table->json('research_notes')->nullable();

            // ICP scoring
            $table->integer('icp_score')->default(0); // 0-100
            $table->json('score_breakdown')->nullable();

            // Status tracking
            $table->string('status')->default('new'); // new, researching, qualified, unqualified, converted
            $table->string('source')->nullable(); // linkedin, web_search, referral, etc.
            $table->string('source_url')->nullable();
            $table->foreignId('discovered_by_agent_run_id')->nullable();

            // Conversion
            $table->foreignId('converted_to_lead_id')->nullable();
            $table->timestamp('converted_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'icp_score']);
            $table->index('company_name');
        });

        // Multi-channel outreach campaigns
        Schema::create('outreach_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->foreignId('icp_id')->nullable()->constrained('ideal_customer_profiles')->nullOnDelete();

            // Campaign settings
            $table->string('status')->default('draft'); // draft, active, paused, completed
            $table->string('type')->default('cold_outreach'); // cold_outreach, nurture, reengagement

            // Targeting
            $table->integer('min_icp_score')->default(60);
            $table->json('target_industries')->nullable();
            $table->json('target_titles')->nullable();

            // Channels
            $table->boolean('use_email')->default(true);
            $table->boolean('use_linkedin')->default(false);
            $table->boolean('use_phone')->default(false);

            // Metrics (denormalized for performance)
            $table->integer('enrolled_count')->default(0);
            $table->integer('sent_count')->default(0);
            $table->integer('opened_count')->default(0);
            $table->integer('replied_count')->default(0);
            $table->integer('converted_count')->default(0);

            $table->timestamps();
        });

        // Campaign sequences (multi-step outreach)
        Schema::create('outreach_sequences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('outreach_campaigns')->cascadeOnDelete();
            $table->integer('step_number')->default(1);

            // Message config
            $table->string('channel')->default('email'); // email, linkedin, phone, manual
            $table->string('subject_template')->nullable();
            $table->text('body_template');

            // Timing
            $table->integer('delay_days')->default(0); // Days after previous step
            $table->string('send_time')->default('09:00'); // Preferred send time
            $table->json('send_days')->nullable(); // ["monday", "tuesday", "wednesday"]

            // Conditions
            $table->string('condition')->default('always'); // always, no_reply, opened, not_opened
            $table->boolean('requires_approval')->default(false);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['campaign_id', 'step_number']);
        });

        // Individual outreach messages
        Schema::create('outreach_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sequence_id')->constrained('outreach_sequences')->cascadeOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();

            // Message content
            $table->string('channel');
            $table->string('subject')->nullable();
            $table->text('body');
            $table->json('personalization_context')->nullable();

            // Status
            $table->string('status')->default('draft'); // draft, approved, scheduled, sent, opened, replied, bounced
            $table->timestamp('scheduled_for')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('replied_at')->nullable();

            // Response
            $table->text('reply_text')->nullable();
            $table->string('reply_sentiment')->nullable(); // positive, neutral, negative

            // Tracking
            $table->foreignId('created_by_agent_run_id')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();

            $table->timestamps();

            $table->index(['status', 'scheduled_for']);
            $table->index(['prospect_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outreach_messages');
        Schema::dropIfExists('outreach_sequences');
        Schema::dropIfExists('outreach_campaigns');
        Schema::dropIfExists('prospects');
        Schema::dropIfExists('ideal_customer_profiles');
    }
};
