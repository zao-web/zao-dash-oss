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
        Schema::table('projects', function (Blueprint $table) {
            $table->string('source')->nullable()->after('status'); // organic, paid, referral, direct
            $table->string('source_page')->nullable()->after('source');
            $table->string('source_keyword')->nullable()->after('source_page');
            $table->decimal('estimated_value', 10, 2)->nullable()->after('source_keyword');
            $table->index(['source', 'source_page']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['source', 'source_page']);
            $table->dropColumn([
                'source',
                'source_page',
                'source_keyword',
                'estimated_value',
            ]);
        });
    }
};
