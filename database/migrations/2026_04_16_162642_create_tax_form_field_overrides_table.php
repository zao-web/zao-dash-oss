<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tax_form_field_overrides', function (Blueprint $table) {
            $table->id();
            $table->string('form_code', 32);
            $table->string('field_key', 64);
            $table->decimal('x', 6, 1);
            $table->decimal('y', 6, 1);
            $table->unsignedTinyInteger('page')->default(1);
            $table->timestamps();

            $table->unique(['form_code', 'field_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tax_form_field_overrides');
    }
};
