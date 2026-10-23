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
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            // sha1 of the URL: article URLs can exceed an indexable string length.
            $table->char('url_hash', 40)->unique();
            $table->text('url');
            $table->string('source');
            $table->string('title', 500);
            $table->text('summary')->nullable();
            $table->text('image_url')->nullable();
            $table->timestamp('published_at')->index();
            $table->float('importance')->nullable();
            $table->float('importance_confidence')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};
