<?php

use App\Upstream\SecurityNews;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CVE and GHSA ids an article mentions, so the news page can say which services they hit.
     */
    public function up(): void
    {
        Schema::table('news_articles', function (Blueprint $table) {
            $table->json('advisory_ids')->nullable();
        });

        // Articles already stored only kept their title and summary; the full text is read for new ones.
        DB::table('news_articles')->select(['id', 'title', 'summary'])->orderBy('id')->each(fn (object $article) => DB::table('news_articles')
            ->where('id', $article->id)
            ->update(['advisory_ids' => json_encode(SecurityNews::advisoryIds($article->title.' '.$article->summary))]));
    }

    public function down(): void
    {
        Schema::table('news_articles', function (Blueprint $table) {
            $table->dropColumn('advisory_ids');
        });
    }
};
