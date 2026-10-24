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
        Schema::table('dependencies', function (Blueprint $table) {
            $table->json('requires')->nullable()->after('version');
            $table->json('usage')->nullable()->after('requires');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->json('requires')->nullable()->after('repository');
            $table->timestamp('usage_scanned_at')->nullable()->after('requires');
            $table->json('fix_plan')->nullable()->after('usage_scanned_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dependencies', function (Blueprint $table) {
            $table->dropColumn(['requires', 'usage']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['requires', 'usage_scanned_at', 'fix_plan']);
        });
    }
};
