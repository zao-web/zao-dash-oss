<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prompt_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('category')->default('task'); // system, task, analysis, content, communication, code
            $table->longText('content'); // The prompt template with {{variables}}
            $table->json('variables')->nullable(); // Variable definitions with types/descriptions
            $table->json('tags')->nullable();
            $table->foreignId('agent_id')->nullable()->constrained()->nullOnDelete(); // Linked agent (optional)
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_public')->default(false); // Shared across team
            $table->json('metadata')->nullable();
            $table->integer('usage_count')->default(0);
            $table->timestamps();

            $table->index('category');
            $table->index('is_active');
            $table->index('agent_id');
        });

        Schema::create('prompt_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_template_id')->constrained()->onDelete('cascade');
            $table->integer('version_number');
            $table->longText('content');
            $table->text('description')->nullable(); // What changed in this version
            $table->json('variables')->nullable();
            $table->boolean('is_active')->default(false);
            $table->integer('ab_test_weight')->default(0); // 0 = not in A/B test, 1-100 = weight
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['prompt_template_id', 'version_number']);
            $table->index('is_active');
        });

        // Add prompt tracking to agent_runs
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->foreignId('prompt_template_id')->nullable()->after('agent_id')
                ->constrained()->nullOnDelete();
            $table->foreignId('prompt_version_id')->nullable()->after('prompt_template_id')
                ->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('agent_runs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('prompt_template_id');
            $table->dropConstrainedForeignId('prompt_version_id');
        });

        Schema::dropIfExists('prompt_versions');
        Schema::dropIfExists('prompt_templates');
    }
};
