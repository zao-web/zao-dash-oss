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
        Schema::create('ads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('ad_set_id')->constrained()->cascadeOnDelete();
            $table->string('ad_id')->unique()->nullable()->comment('Meta ad ID');
            $table->string('name');
            $table->enum('status', ['draft', 'pending_approval', 'active', 'paused'])->default('draft');
            $table->enum('creative_type', ['single_image', 'carousel', 'video'])->default('single_image');
            $table->foreignId('ad_creative_id')->nullable()->constrained()->nullOnDelete();
            $table->string('call_to_action')->nullable()->comment('LEARN_MORE, SHOP_NOW, etc.');
            $table->text('destination_url');
            $table->json('tracking_specs')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('ad_set_id');
            $table->index('ad_creative_id');
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ads');
    }
};
