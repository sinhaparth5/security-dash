<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per service per day, written nightly: its grade and how far its dependencies have drifted.
     * Read for trends, never for grading.
     */
    public function up(): void
    {
        Schema::create('service_snapshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->date('date');
            $table->string('status');
            $table->unsignedInteger('dependencies');
            $table->unsignedInteger('outdated');
            $table->unsignedInteger('majors_behind');
            $table->unsignedInteger('end_of_life');
            $table->unique(['service_id', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_snapshots');
    }
};
