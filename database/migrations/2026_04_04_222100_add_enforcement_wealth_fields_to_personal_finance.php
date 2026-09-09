<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Tax obligations: enforcement & compliance fields
        Schema::table('tax_obligations', function (Blueprint $table) {
            $table->json('notice_history')->nullable()->after('next_action_date');
            $table->boolean('lien_filed')->default(false)->after('notice_history');
            $table->boolean('levy_issued')->default(false)->after('lien_filed');
            $table->boolean('cdp_requested')->default(false)->after('levy_issued');
            $table->boolean('passport_certified')->default(false)->after('cdp_requested');
            $table->boolean('filing_compliance')->default(true)->after('passport_certified');
            $table->string('form_433_status')->nullable()->after('filing_compliance');
        });

        // Collections accounts: validation & legal fields
        Schema::table('collections_accounts', function (Blueprint $table) {
            $table->date('validation_notice_date')->nullable()->after('correspondence_log');
            $table->date('dispute_deadline')->nullable()->after('validation_notice_date');
            $table->boolean('verification_received')->default(false)->after('dispute_deadline');
            $table->boolean('cease_communication')->default(false)->after('verification_received');
            $table->boolean('lawsuit_filed')->default(false)->after('cease_communication');
            $table->boolean('judgment_entered')->default(false)->after('lawsuit_filed');
            $table->boolean('garnishment_active')->default(false)->after('judgment_entered');
        });

        // Debts: enforcement & collateral fields
        Schema::table('debts', function (Blueprint $table) {
            $table->string('enforcement_status')->nullable()->after('status');
            $table->boolean('secured')->default(false)->after('enforcement_status');
            $table->string('collateral_description')->nullable()->after('secured');
        });

        // Net worth snapshots: periodic wealth tracking
        Schema::create('net_worth_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('snapshot_date');
            $table->decimal('total_assets', 14, 2);
            $table->decimal('total_liabilities', 14, 2);
            $table->decimal('net_worth', 14, 2);
            $table->decimal('personal_cash', 14, 2);
            $table->decimal('business_cash', 14, 2);
            $table->decimal('investment_value', 14, 2);
            $table->decimal('total_debt', 14, 2);
            $table->json('breakdown')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'snapshot_date']);
        });

        // Financial goals: wealth building targets
        Schema::create('financial_goals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('goal_type');
            $table->decimal('target_amount', 14, 2);
            $table->decimal('current_amount', 14, 2)->default(0);
            $table->date('target_date')->nullable();
            $table->string('priority')->default('medium');
            $table->string('status')->default('active');
            $table->foreignId('linked_account_id')->nullable()->constrained('personal_accounts')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        // Profitability views: denormalized client/project profitability
        Schema::create('profitability_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->decimal('revenue', 14, 2);
            $table->decimal('cost', 14, 2);
            $table->decimal('profit', 14, 2);
            $table->decimal('margin', 5, 2);
            $table->decimal('hours_logged', 8, 2);
            $table->decimal('effective_rate', 8, 2);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('profitability_views');
        Schema::dropIfExists('financial_goals');
        Schema::dropIfExists('net_worth_snapshots');

        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['enforcement_status', 'secured', 'collateral_description']);
        });

        Schema::table('collections_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'validation_notice_date',
                'dispute_deadline',
                'verification_received',
                'cease_communication',
                'lawsuit_filed',
                'judgment_entered',
                'garnishment_active',
            ]);
        });

        Schema::table('tax_obligations', function (Blueprint $table) {
            $table->dropColumn([
                'notice_history',
                'lien_filed',
                'levy_issued',
                'cdp_requested',
                'passport_certified',
                'filing_compliance',
                'form_433_status',
            ]);
        });
    }
};
