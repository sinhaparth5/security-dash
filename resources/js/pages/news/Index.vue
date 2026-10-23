<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { Newspaper, Sparkles } from '@lucide/vue';
import { reactive } from 'vue';
import { lastSeen } from '@/lib/format';
import { index as newsIndex } from '@/routes/news';
import type { NewsArticle } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'News', href: newsIndex() }],
    },
});

defineProps<{
    featured: NewsArticle[];
    important: NewsArticle[];
    latest: NewsArticle[];
    ranked: boolean;
    checkedAt: string | null;
}>();

// Feeds refresh hourly; checking every five minutes picks up a new run soon after it lands.
usePoll(300_000);

// Publisher images break now and then; fall back to the placeholder instead of a broken icon.
const brokenImages = reactive(new Set<number>());

function hasImage(article: NewsArticle): boolean {
    return article.image_url !== null && !brokenImages.has(article.id);
}

function rank(
    article: NewsArticle,
): { label: string; class: string; title: string } | null {
    if (article.importance === null) {
        return null;
    }

    const confidence = Math.round((article.importance_confidence ?? 0) * 100);
    const title = `Ranked by Jev for the software your services run (confidence ${confidence}%). A suggestion, not a grade.`;

    if (article.importance >= 2.5) {
        return {
            label: 'Act now',
            class: 'bg-status-critical text-status-critical-foreground',
            title,
        };
    }

    return article.importance >= 1.5
        ? {
              label: 'Important',
              class: 'bg-status-warning text-status-warning-foreground',
              title,
          }
        : null;
}
</script>

<template>
    <Head title="Security news" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-[28px] font-bold tracking-tight lowercase">
                Security news
            </h1>
            <p class="max-w-3xl text-sm text-muted-foreground">
                From The Hacker News, BleepingComputer, SecurityWeek, Krebs on
                Security and Dark Reading, refreshed every hour.
                <template v-if="ranked">
                    Jev ranks each article by how much it matters for the
                    software your services run.
                </template>
                <template v-if="checkedAt">
                    Last checked {{ lastSeen(checkedAt).toLowerCase() }}.
                </template>
            </p>
        </header>

        <section
            v-if="!featured.length && !important.length && !latest.length"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-center"
        >
            <Newspaper
                class="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 class="font-medium">No news yet</h2>
            <p class="text-sm text-muted-foreground">
                The feeds are read every hour by the scheduler. Check that the
                scheduler and queue worker are running.
            </p>
        </section>

        <section
            v-if="featured.length"
            aria-labelledby="featured-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="featured-heading" class="text-lg font-semibold">
                Featured
            </h2>
            <div class="grid gap-4 lg:grid-cols-3 lg:grid-rows-2">
                <article
                    v-for="(article, index) in featured"
                    :key="article.id"
                    class="group relative flex flex-col overflow-hidden rounded-lg border bg-card shadow-card transition-[border-color] duration-200 focus-within:ring-[3px] focus-within:ring-ring/50 hover:border-ring/40"
                    :class="{ 'lg:col-span-2 lg:row-span-2': index === 0 }"
                >
                    <div
                        class="relative overflow-hidden bg-muted"
                        :class="index === 0 ? 'aspect-[16/9]' : 'aspect-[16/7]'"
                    >
                        <img
                            v-if="hasImage(article)"
                            :src="article.image_url!"
                            alt=""
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            class="size-full object-cover transition-transform duration-300 group-hover:scale-[1.02] motion-reduce:transition-none"
                            @error="brokenImages.add(article.id)"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center gap-2 bg-gradient-to-br from-muted to-accent text-muted-foreground"
                        >
                            <Newspaper class="size-6" aria-hidden="true" />
                            <span class="text-sm font-medium">{{
                                article.source
                            }}</span>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div
                            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                v-if="rank(article)"
                                class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium"
                                :class="rank(article)!.class"
                                :title="rank(article)!.title"
                            >
                                <Sparkles class="size-3" aria-hidden="true" />
                                {{ rank(article)!.label }}
                            </span>
                            <span class="font-medium text-foreground">{{
                                article.source
                            }}</span>
                            <time :datetime="article.published_at">{{
                                lastSeen(article.published_at)
                            }}</time>
                        </div>
                        <h3
                            class="font-semibold text-balance"
                            :class="
                                index === 0
                                    ? 'text-xl leading-snug md:text-2xl'
                                    : 'leading-snug'
                            "
                        >
                            <a
                                :href="article.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="after:absolute after:inset-0 focus-visible:outline-none"
                                >{{ article.title
                                }}<span class="sr-only">
                                    (opens in a new tab)</span
                                ></a
                            >
                        </h3>
                        <p
                            v-if="article.summary"
                            class="text-sm leading-relaxed text-muted-foreground"
                            :class="
                                index === 0 ? 'line-clamp-4' : 'line-clamp-2'
                            "
                        >
                            {{ article.summary }}
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <section
            v-if="important.length"
            aria-labelledby="important-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="important-heading" class="text-lg font-semibold">
                Important
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="article in important"
                    :key="article.id"
                    class="group relative flex flex-col overflow-hidden rounded-lg border bg-card shadow-card transition-[border-color] duration-200 focus-within:ring-[3px] focus-within:ring-ring/50 hover:border-ring/40"
                >
                    <div class="aspect-[16/9] overflow-hidden bg-muted">
                        <img
                            v-if="hasImage(article)"
                            :src="article.image_url!"
                            alt=""
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            class="size-full object-cover"
                            @error="brokenImages.add(article.id)"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center gap-2 bg-gradient-to-br from-muted to-accent text-muted-foreground"
                        >
                            <Newspaper class="size-5" aria-hidden="true" />
                            <span class="text-sm font-medium">{{
                                article.source
                            }}</span>
                        </div>
                    </div>
                    <div class="flex flex-1 flex-col gap-2 p-4">
                        <div
                            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span
                                v-if="rank(article)"
                                class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium"
                                :class="rank(article)!.class"
                                :title="rank(article)!.title"
                            >
                                <Sparkles class="size-3" aria-hidden="true" />
                                {{ rank(article)!.label }}
                            </span>
                            <span class="font-medium text-foreground">{{
                                article.source
                            }}</span>
                            <time :datetime="article.published_at">{{
                                lastSeen(article.published_at)
                            }}</time>
                        </div>
                        <h3 class="leading-snug font-semibold text-balance">
                            <a
                                :href="article.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="after:absolute after:inset-0 focus-visible:outline-none"
                                >{{ article.title
                                }}<span class="sr-only">
                                    (opens in a new tab)</span
                                ></a
                            >
                        </h3>
                        <p
                            v-if="article.summary"
                            class="line-clamp-3 text-sm leading-relaxed text-muted-foreground"
                        >
                            {{ article.summary }}
                        </p>
                    </div>
                </article>
            </div>
        </section>

        <section
            v-if="latest.length"
            aria-labelledby="latest-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="latest-heading" class="text-lg font-semibold">
                {{
                    featured.length || important.length ? 'Latest' : 'All news'
                }}
            </h2>
            <ul
                class="divide-y overflow-hidden rounded-lg border bg-card shadow-card"
            >
                <li
                    v-for="article in latest"
                    :key="article.id"
                    class="group relative flex gap-4 p-4 transition-colors focus-within:bg-accent/50 hover:bg-accent/50"
                >
                    <div
                        class="hidden aspect-[4/3] w-28 shrink-0 overflow-hidden rounded-md bg-muted sm:block"
                    >
                        <img
                            v-if="hasImage(article)"
                            :src="article.image_url!"
                            alt=""
                            loading="lazy"
                            referrerpolicy="no-referrer"
                            class="size-full object-cover"
                            @error="brokenImages.add(article.id)"
                        />
                        <div
                            v-else
                            class="flex size-full items-center justify-center text-muted-foreground"
                        >
                            <Newspaper class="size-5" aria-hidden="true" />
                        </div>
                    </div>
                    <div class="flex min-w-0 flex-col gap-1">
                        <div
                            class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
                        >
                            <span class="font-medium text-foreground">{{
                                article.source
                            }}</span>
                            <time :datetime="article.published_at">{{
                                lastSeen(article.published_at)
                            }}</time>
                        </div>
                        <h3 class="leading-snug font-medium">
                            <a
                                :href="article.url"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="after:absolute after:inset-0 focus-visible:outline-none"
                                >{{ article.title
                                }}<span class="sr-only">
                                    (opens in a new tab)</span
                                ></a
                            >
                        </h3>
                        <p
                            v-if="article.summary"
                            class="line-clamp-2 text-sm text-muted-foreground"
                        >
                            {{ article.summary }}
                        </p>
                    </div>
                </li>
            </ul>
        </section>
    </div>
</template>
