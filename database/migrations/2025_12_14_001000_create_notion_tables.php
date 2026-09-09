<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Notion workspace connections
        Schema::create('notion_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('workspace_id');
            $table->string('workspace_name');
            $table->string('workspace_icon')->nullable();
            $table->text('access_token');
            $table->string('bot_id');
            $table->string('owner_type')->default('user');
            $table->string('owner_id')->nullable();
            $table->string('duplicated_template_id')->nullable();
            $table->string('request_id')->nullable();
            $table->timestamp('connected_at');
            $table->timestamps();

            $table->unique('workspace_id');
        });

        // Synced Notion pages and databases
        Schema::create('notion_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notion_connection_id')->constrained()->cascadeOnDelete();
            $table->string('page_id')->unique();
            $table->string('parent_type')->nullable();
            $table->string('parent_id')->nullable();
            $table->string('title', 500);
            $table->string('icon')->nullable();
            $table->string('cover_url', 500)->nullable();
            $table->string('url', 500);
            $table->boolean('is_database')->default(false);
            $table->json('properties_schema')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('last_synced_at')->nullable();
            $table->boolean('archived')->default(false);
            $table->timestamps();

            $table->index('notion_connection_id');
            $table->index('parent_id');
            $table->index('client_id');
        });

        // Page content cache
        Schema::create('notion_page_contents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notion_page_id')->constrained()->cascadeOnDelete();
            $table->json('content_blocks')->nullable();
            $table->longText('plain_text')->nullable();
            $table->timestamp('synced_at');
            $table->timestamps();
        });

        // Database items (rows in Notion databases)
        Schema::create('notion_database_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notion_page_id')->constrained()->cascadeOnDelete();
            $table->string('item_id');
            $table->json('properties');
            $table->string('title', 500)->nullable();
            $table->string('status', 100)->nullable();
            $table->string('priority', 50)->nullable();
            $table->string('assignee')->nullable();
            $table->date('due_date')->nullable();
            $table->string('url', 500);
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index('notion_page_id');
            $table->index('status');
            $table->unique('item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notion_database_items');
        Schema::dropIfExists('notion_page_contents');
        Schema::dropIfExists('notion_pages');
        Schema::dropIfExists('notion_connections');
    }
};
