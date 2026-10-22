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
        Schema::create('advisories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dependency_id')->constrained()->cascadeOnDelete();
            $table->string('osv_id');
            $table->json('aliases');
            $table->string('severity')->nullable();
            $table->text('summary')->nullable();
            $table->string('fixed_version')->nullable();
            $table->timestamps();

            $table->unique(['dependency_id', 'osv_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('advisories');
    }
};
