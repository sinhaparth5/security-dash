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
            $table->json('linked_symbols')->nullable()->after('usage');
            $table->json('supply_chain')->nullable()->after('linked_symbols');
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->json('affected_symbols')->nullable()->after('affected_packages');
            $table->boolean('reachable')->nullable()->after('affected_symbols');
        });

        Schema::table('services', function (Blueprint $table) {
            $table->boolean('watch_repository')->default(false)->after('repository');
            $table->string('fix_plan_pull_request_url')->nullable()->after('fix_plan');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('dependencies', function (Blueprint $table) {
            $table->dropColumn(['linked_symbols', 'supply_chain']);
        });

        Schema::table('advisories', function (Blueprint $table) {
            $table->dropColumn(['affected_symbols', 'reachable']);
        });

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['watch_repository', 'fix_plan_pull_request_url']);
        });
    }
};
