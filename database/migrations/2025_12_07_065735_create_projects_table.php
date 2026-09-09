<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id');
            $table->string('name');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->enum('status', ['active', 'on_hold', 'completed', 'archived'])->default('active');
            $table->enum('type', ['retainer', 'project', 'support'])->default('project');
            $table->decimal('budget', 10, 2)->nullable();
            $table->string('github_repo')->nullable();
            $table->string('notion_page_id')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['client_id', 'slug']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
