<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Harvest credentials (OAuth)
        Schema::create('harvest_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token');
            $table->timestamp('expires_at');
            $table->bigInteger('account_id')->nullable();
            $table->string('account_name')->nullable();
            $table->timestamps();

            $table->unique('user_id');
        });

        // Harvest projects (linked to our projects)
        Schema::create('harvest_projects', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('harvest_id')->unique();
            $table->bigInteger('harvest_client_id')->nullable();
            $table->string('name');
            $table->string('code')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_billable')->default(true);
            $table->string('bill_by')->nullable(); // Project, Tasks, People, none
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('budget', 12, 2)->nullable();
            $table->string('budget_by')->nullable(); // project, task, none
            $table->boolean('budget_is_monthly')->default(false);
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->index('client_id');
            $table->index('project_id');
        });

        // Harvest task categories (Development, Meetings, etc.)
        Schema::create('harvest_task_categories', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('harvest_id')->unique();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->decimal('default_hourly_rate', 10, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Time entries
        Schema::create('time_entries', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('harvest_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('task_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('harvest_project_id');
            $table->bigInteger('harvest_task_id');
            $table->decimal('hours', 6, 2);
            $table->text('notes')->nullable();
            $table->date('spent_date');
            $table->boolean('is_running')->default(false);
            $table->timestamp('timer_started_at')->nullable();
            $table->boolean('is_billable')->default(true);
            $table->boolean('is_billed')->default(false);
            $table->decimal('hourly_rate', 10, 2)->nullable();
            $table->decimal('cost_rate', 10, 2)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'spent_date']);
            $table->index('client_id');
            $table->index('project_id');
            $table->index('is_running');
        });

        // Invoices
        Schema::create('harvest_invoices', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('harvest_id')->unique();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('harvest_client_id');
            $table->string('number')->nullable();
            $table->string('subject')->nullable();
            $table->string('state'); // draft, open, paid, closed
            $table->decimal('amount', 12, 2);
            $table->decimal('due_amount', 12, 2)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('due_date')->nullable();
            $table->date('sent_at')->nullable();
            $table->date('paid_at')->nullable();
            $table->string('currency')->default('USD');
            $table->timestamps();

            $table->index('client_id');
            $table->index('state');
        });

        // Project budgets and tracking
        Schema::create('project_budgets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('harvest_project_id')->nullable();
            $table->decimal('budget_hours', 8, 2)->nullable();
            $table->decimal('budget_amount', 12, 2)->nullable();
            $table->string('billing_type')->default('hourly'); // hourly, fixed, retainer
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->decimal('hours_logged', 8, 2)->default(0);
            $table->decimal('amount_billed', 12, 2)->default(0);
            $table->timestamp('last_activity_at')->nullable();
            $table->string('status')->default('on_track'); // on_track, at_risk, over_budget
            $table->timestamps();

            $table->unique('project_id');
        });

        // Retainer periods
        Schema::create('retainer_periods', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->decimal('hours_included', 6, 2);
            $table->decimal('hours_used', 6, 2)->default(0);
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('rollover_hours', 6, 2)->default(0);
            $table->decimal('overage_rate', 10, 2)->nullable();
            $table->string('status')->default('active'); // active, completed, invoiced
            $table->timestamps();

            $table->index(['client_id', 'period_start']);
        });

        // Profitability snapshots
        Schema::create('profitability_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('period_type'); // daily, weekly, monthly
            $table->date('period_start');
            $table->date('period_end');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('hours_logged', 8, 2)->default(0);
            $table->decimal('hours_billable', 8, 2)->default(0);
            $table->decimal('revenue', 12, 2)->default(0);
            $table->decimal('cost', 12, 2)->default(0);
            $table->decimal('profit', 12, 2)->default(0);
            $table->decimal('margin_percent', 5, 2)->nullable();
            $table->timestamps();

            $table->index(['period_type', 'period_start']);
            $table->index('client_id');
            $table->index('project_id');
        });

        // Client reports
        Schema::create('client_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('report_type')->default('monthly'); // monthly, quarterly, custom
            $table->json('data_snapshot')->nullable();
            $table->text('executive_summary')->nullable();
            $table->string('pdf_path')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->json('sent_to')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'period_start']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_reports');
        Schema::dropIfExists('profitability_snapshots');
        Schema::dropIfExists('retainer_periods');
        Schema::dropIfExists('project_budgets');
        Schema::dropIfExists('harvest_invoices');
        Schema::dropIfExists('time_entries');
        Schema::dropIfExists('harvest_task_categories');
        Schema::dropIfExists('harvest_projects');
        Schema::dropIfExists('harvest_credentials');
    }
};
