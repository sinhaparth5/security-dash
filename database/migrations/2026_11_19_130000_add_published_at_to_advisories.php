<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * When OSV published each advisory, so the dashboard can measure how late we first saw it.
     * Null for synthetic advisories (EOL-, node security releases) and for rows graded before this.
     */
    public function up(): void
    {
        foreach (['advisories', 'advisory_history'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->timestamp('published_at')->nullable());
        }
    }

    public function down(): void
    {
        foreach (['advisories', 'advisory_history'] as $table) {
            Schema::table($table, fn (Blueprint $table) => $table->dropColumn('published_at'));
        }
    }
};
