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
        Schema::create('ad_performance', function (Blueprint $table) {
            $table->id();
            $table->string('performable_type')->comment('Ad, AdSet, or AdCampaign');
            $table->unsignedBigInteger('performable_id');
            $table->date('date');
            $table->integer('hour')->nullable()->comment('For hourly granularity');
            $table->unsignedBigInteger('impressions')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->decimal('spend', 10, 2)->default(0);
            $table->unsignedInteger('conversions')->default(0);
            $table->decimal('conversion_value', 10, 2)->default(0);
            $table->unsignedBigInteger('reach')->nullable();
            $table->decimal('frequency', 10, 2)->nullable();
            $table->decimal('ctr', 10, 4)->default(0)->comment('Computed: clicks/impressions');
            $table->decimal('cpc', 10, 2)->default(0)->comment('Computed: spend/clicks');
            $table->decimal('cpa', 10, 2)->nullable()->comment('Computed: spend/conversions');
            $table->decimal('roas', 10, 2)->nullable()->comment('Computed: conversion_value/spend');
            $table->json('metadata')->nullable()->comment('Additional platform-specific metrics');
            $table->timestamp('synced_at');
            $table->timestamps();

            $table->index(['performable_type', 'performable_id']);
            $table->index('date');
            $table->unique(['performable_type', 'performable_id', 'date', 'hour']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ad_performance');
    }
};
