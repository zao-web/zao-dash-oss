<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add harvest_contact_id to track synced contacts from Harvest.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            if (! Schema::hasColumn('client_contacts', 'harvest_contact_id')) {
                $table->bigInteger('harvest_contact_id')->nullable()->unique()->after('id');
            }
            if (! Schema::hasColumn('client_contacts', 'title')) {
                $table->string('title')->nullable()->after('role');
            }
        });
    }

    public function down(): void
    {
        Schema::table('client_contacts', function (Blueprint $table) {
            if (Schema::hasColumn('client_contacts', 'harvest_contact_id')) {
                $table->dropColumn('harvest_contact_id');
            }
            if (Schema::hasColumn('client_contacts', 'title')) {
                $table->dropColumn('title');
            }
        });
    }
};
