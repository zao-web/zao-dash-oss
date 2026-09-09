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
        Schema::table('leads', function (Blueprint $table) {
            // UTM attribution columns
            if (! Schema::hasColumn('leads', 'first_touch_source')) {
                $table->string('first_touch_source')->nullable()->after('first_touch_keyword');
            }
            if (! Schema::hasColumn('leads', 'first_touch_medium')) {
                $table->string('first_touch_medium')->nullable()->after('first_touch_source');
            }
            if (! Schema::hasColumn('leads', 'first_touch_campaign')) {
                $table->string('first_touch_campaign')->nullable()->after('first_touch_medium');
            }

            // Last touch attribution
            if (! Schema::hasColumn('leads', 'last_touch_page_url')) {
                $table->string('last_touch_page_url', 500)->nullable()->after('first_touch_campaign');
            }

            // Engagement metrics
            if (! Schema::hasColumn('leads', 'pages_viewed')) {
                $table->integer('pages_viewed')->nullable()->after('last_touch_page_url');
            }
            if (! Schema::hasColumn('leads', 'time_on_site_seconds')) {
                $table->integer('time_on_site_seconds')->nullable()->after('pages_viewed');
            }
            if (! Schema::hasColumn('leads', 'max_scroll_depth')) {
                $table->integer('max_scroll_depth')->nullable()->after('time_on_site_seconds');
            }

            // GA4 session linkage
            if (! Schema::hasColumn('leads', 'ga4_session_id')) {
                $table->string('ga4_session_id', 100)->nullable()->after('ga4_client_id');
            }

            // SEO page linkage for attribution
            if (! Schema::hasColumn('leads', 'seo_page_id')) {
                $table->foreignId('seo_page_id')->nullable()->after('ga4_session_id')
                    ->constrained('seo_pages')->nullOnDelete();
            }
        });

    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Must drop foreign key constraint BEFORE dropping the column
        Schema::table('leads', function (Blueprint $table) {
            if (Schema::hasColumn('leads', 'seo_page_id')) {
                $table->dropForeign(['seo_page_id']);
            }
        });

        Schema::table('leads', function (Blueprint $table) {
            $columns = [
                'first_touch_source',
                'first_touch_medium',
                'first_touch_campaign',
                'last_touch_page_url',
                'pages_viewed',
                'time_on_site_seconds',
                'max_scroll_depth',
                'ga4_session_id',
                'seo_page_id',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('leads', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
