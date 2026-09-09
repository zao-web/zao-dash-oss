<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Add harvest_client_id to clients for bidirectional sync
        if (! Schema::hasColumn('clients', 'harvest_client_id')) {
            Schema::table('clients', function (Blueprint $table) {
                $table->unsignedBigInteger('harvest_client_id')->nullable()->after('id');
                $table->index('harvest_client_id');
            });
        }

        // Add harvest_project_id to projects for bidirectional sync
        if (! Schema::hasColumn('projects', 'harvest_project_id')) {
            Schema::table('projects', function (Blueprint $table) {
                $table->unsignedBigInteger('harvest_project_id')->nullable()->after('client_id');
                $table->index('harvest_project_id');
            });
        }

        // Add harvest_project_id to retainer_periods for budget sync
        if (! Schema::hasColumn('retainer_periods', 'harvest_project_id')) {
            Schema::table('retainer_periods', function (Blueprint $table) {
                $table->unsignedBigInteger('harvest_project_id')->nullable()->after('client_id');
            });
        }

        // Add client_id to harvest_invoices if missing
        if (! Schema::hasColumn('harvest_invoices', 'client_id')) {
            Schema::table('harvest_invoices', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->after('id')->constrained()->nullOnDelete();
            });
        }

        // Add project_id and client_id to harvest_projects if missing
        if (! Schema::hasColumn('harvest_projects', 'client_id')) {
            Schema::table('harvest_projects', function (Blueprint $table) {
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('harvest_projects', 'project_id')) {
            Schema::table('harvest_projects', function (Blueprint $table) {
                $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            });
        }

        // Add client_harvest_id to harvest_projects for linking
        if (! Schema::hasColumn('harvest_projects', 'client_harvest_id')) {
            Schema::table('harvest_projects', function (Blueprint $table) {
                $table->unsignedBigInteger('client_harvest_id')->nullable();
                $table->string('client_name')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropIndex(['harvest_client_id']);
            $table->dropColumn('harvest_client_id');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['harvest_project_id']);
            $table->dropColumn('harvest_project_id');
        });

        Schema::table('retainer_periods', function (Blueprint $table) {
            $table->dropColumn('harvest_project_id');
        });
    }
};
