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
        Schema::table('services', function (Blueprint $table) {
            $table->json('allowed_ips')->nullable();
            $table->timestamp('status_changed_at')->nullable();
            $table->timestamp('evaluated_at')->nullable();
        });

        Schema::create('status_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['service_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('status_changes');

        Schema::table('services', function (Blueprint $table) {
            $table->dropColumn(['allowed_ips', 'status_changed_at', 'evaluated_at']);
        });
    }
};
