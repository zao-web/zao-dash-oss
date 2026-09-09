<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Configurable RFP discovery sources
        Schema::create('rfp_sources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type'); // email_sender, government_api, rfp_board, rss_feed, web_scrape
            $table->json('config'); // Type-specific config
            $table->boolean('is_active')->default(true);
            $table->integer('check_frequency_minutes')->default(60);
            $table->timestamp('last_checked_at')->nullable();
            $table->integer('total_opportunities_found')->default(0);
            $table->json('filters')->nullable(); // keyword, industry, budget filters
            $table->timestamps();
        });

        // Central RFP/bid opportunity entity
        Schema::create('rfp_opportunities', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->string('issuing_organization');
            $table->string('organization_website')->nullable();
            $table->string('organization_industry')->nullable();
            $table->text('description')->nullable();

            // Source tracking
            $table->string('source_type'); // email_teaser, sam_gov, rfp_board, web_scrape, manual
            $table->string('source_identifier')->nullable();
            $table->string('source_url')->nullable();
            $table->string('discovered_by')->nullable();
            $table->foreignId('rfp_source_id')->nullable()->constrained()->nullOnDelete();

            // Full document
            $table->string('full_document_url')->nullable();
            $table->string('full_document_path')->nullable();
            $table->string('full_document_google_id')->nullable();

            // Budget
            $table->decimal('budget_min', 14, 2)->nullable();
            $table->decimal('budget_max', 14, 2)->nullable();

            // Submission details
            $table->timestamp('submission_deadline')->nullable();
            $table->string('submission_method')->nullable(); // email, portal, physical
            $table->string('submission_email')->nullable();
            $table->string('submission_portal_url')->nullable();

            // Contact info
            $table->string('contact_name')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();

            // Pipeline status
            $table->string('status')->default('discovered');
            $table->string('priority')->default('medium');
            $table->string('decline_reason')->nullable();

            // AI scoring
            $table->integer('fit_score')->default(0);
            $table->json('fit_score_breakdown')->nullable();

            // Parsed document data
            $table->json('tech_requirements')->nullable();
            $table->json('requirements_summary')->nullable();
            $table->json('parsed_sections')->nullable();
            $table->json('timeline_requirements')->nullable();
            $table->json('evaluation_criteria')->nullable();
            $table->json('tags')->nullable();

            // Relationships
            $table->foreignId('lead_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('prospect_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('discovered_by_agent_run_id')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['status', 'fit_score']);
            $table->index('source_type');
            $table->index('submission_deadline');
            $table->index('issuing_organization');
        });

        // Generated proposals for RFP opportunities
        Schema::create('rfp_proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfp_opportunity_id')->constrained()->cascadeOnDelete();
            $table->integer('version')->default(1);
            $table->string('title');
            $table->text('executive_summary')->nullable();
            $table->longText('full_content')->nullable();

            // Structured content
            $table->json('proposal_sections')->nullable();
            $table->json('pricing_breakdown')->nullable();
            $table->decimal('total_price', 14, 2)->nullable();

            // Evidence used
            $table->json('case_studies_used')->nullable();
            $table->json('testimonials_used')->nullable();
            $table->json('past_projects_cited')->nullable();

            // Context
            $table->string('tone_profile')->nullable();
            $table->json('research_context')->nullable();
            $table->json('requirement_responses')->nullable();

            // Lifecycle
            $table->string('status')->default('draft'); // draft, review, approved, submitted, superseded
            $table->foreignId('generated_by_agent_run_id')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->string('submitted_via')->nullable();

            // Storage
            $table->string('google_drive_id')->nullable();
            $table->string('pdf_path')->nullable();

            $table->timestamps();

            $table->index(['rfp_opportunity_id', 'version']);
        });

        // Win/loss tracking with feedback
        Schema::create('rfp_outcomes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rfp_opportunity_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rfp_proposal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('outcome'); // won, lost, no_response, withdrawn
            $table->string('feedback_source')->nullable();
            $table->text('feedback_raw')->nullable();
            $table->json('feedback_structured')->nullable();
            $table->json('win_factors')->nullable();
            $table->json('loss_factors')->nullable();
            $table->json('competitor_info')->nullable();
            $table->json('price_comparison')->nullable();
            $table->json('lessons_learned')->nullable();
            $table->boolean('organization_would_bid_again')->nullable();
            $table->decimal('score_received', 5, 2)->nullable();
            $table->string('awarded_to')->nullable();
            $table->decimal('awarded_amount', 14, 2)->nullable();
            $table->boolean('follow_up_opportunity')->default(false);
            $table->timestamps();
        });

        // Aggregated learning insights from outcome patterns
        Schema::create('rfp_learning_insights', function (Blueprint $table) {
            $table->id();
            $table->string('insight_type'); // win_pattern, loss_pattern, pricing_insight, industry_trend, content_improvement
            $table->string('title');
            $table->text('description');
            $table->json('evidence')->nullable();
            $table->decimal('confidence', 3, 2)->default(0.00);
            $table->string('impact_area'); // pricing, content, targeting, process, presentation
            $table->text('actionable_recommendation')->nullable();
            $table->integer('applied_to_proposals')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('generated_by_agent_run_id')->nullable();
            $table->timestamps();

            $table->index(['insight_type', 'is_active']);
            $table->index('impact_area');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rfp_learning_insights');
        Schema::dropIfExists('rfp_outcomes');
        Schema::dropIfExists('rfp_proposals');
        Schema::dropIfExists('rfp_opportunities');
        Schema::dropIfExists('rfp_sources');
    }
};
