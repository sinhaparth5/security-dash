<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The trend reads snapshots now, so replay status_changes once into status-only rows for the last 30 days.
     * Drift was never measured for those days, so its columns become nullable and stay null there.
     */
    public function up(): void
    {
        Schema::table('service_snapshots', function (Blueprint $table) {
            foreach (['dependencies', 'outdated', 'majors_behind', 'end_of_life'] as $column) {
                $table->unsignedInteger($column)->nullable()->change();
            }
        });

        $changes = DB::table('status_changes')->orderBy('created_at')->orderBy('id')->get(['service_id', 'status', 'created_at'])->groupBy('service_id');

        foreach (DB::table('services')->get(['id', 'status', 'created_at']) as $service) {
            $timeline = $changes->get($service->id, collect());
            $rows = [];

            foreach (range(30, 1) as $daysAgo) {
                $endOfDay = now()->subDays($daysAgo)->endOfDay();
                $change = $timeline->last(fn (stdClass $change) => $change->created_at <= $endOfDay->toDateTimeString());
                // Graded before status history existed: its current status, from registration (as the replay did).
                $status = $change->status ?? ($timeline->isEmpty() && $service->created_at !== null && $service->created_at <= $endOfDay->toDateTimeString() ? $service->status : null);

                if ($status !== null) {
                    $rows[] = ['service_id' => $service->id, 'date' => $endOfDay->toDateString(), 'status' => $status];
                }
            }

            DB::table('service_snapshots')->insertOrIgnore($rows);
        }
    }

    public function down(): void
    {
        DB::table('service_snapshots')->whereNull('dependencies')->delete();
    }
};
