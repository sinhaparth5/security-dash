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
        Schema::table('advisories', function (Blueprint $table) {
            $table->json('triage')->nullable();
        });

        Schema::table('services', function (Blueprint $table) {
            $table->string('repository')->nullable();
        });

        Schema::table('dependencies', function (Blueprint $table) {
            $table->string('pull_request_url')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dependencies', function (Blueprint $table) {
            $table->dropColumn('pull_request_url');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn('repository');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn('triage');
        });
    }
};
