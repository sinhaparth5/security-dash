<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per advisory a service has carried, by package rather than dependency row, so it outlives the
     * advisories table (rebuilt every evaluation) and dependency rows (replaced on every report). resolved_at
     * marks the evaluation that no longer found it: the fix.
     */
    public function up(): void
    {
        Schema::create('advisory_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('ecosystem');
            $table->string('package');
            $table->string('osv_id');
            $table->string('severity')->nullable();
            $table->timestamp('first_seen_at');
            $table->timestamp('resolved_at')->nullable()->index();
            $table->unique(['service_id', 'ecosystem', 'package', 'osv_id']);
        });

        // Start from what is open now; fixes from before this migration were never recorded.
        DB::table('advisories')->join('dependencies', 'dependencies.id', '=', 'advisories.dependency_id')
            ->groupBy('dependencies.service_id', 'dependencies.ecosystem', 'dependencies.name', 'advisories.osv_id')
            ->selectRaw('dependencies.service_id, dependencies.ecosystem, dependencies.name as package, advisories.osv_id, max(advisories.severity) as severity, min(coalesce(advisories.first_seen_at, advisories.created_at)) as first_seen_at')
            ->orderBy('dependencies.service_id')
            ->chunk(500, fn ($rows) => DB::table('advisory_history')->insert($rows->map(fn (object $row) => (array) $row)->all()));
    }

    public function down(): void
    {
        Schema::dropIfExists('advisory_history');
    }
};
