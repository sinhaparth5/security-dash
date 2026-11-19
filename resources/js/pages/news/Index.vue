<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { Newspaper, Search, Server } from '@lucide/vue';
import { computed, reactive, ref } from 'vue';
import NewsWire from '@/components/ascii/NewsWire.vue';
import NewsMeta from '@/components/NewsMeta.vue';
import { Input } from '@/components/ui/input';
import { lastSeen } from '@/lib/format';
import { index as newsIndex } from '@/routes/news';
import type { NewsArticle } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'News', href: newsIndex() }],
    },
});

const props = defineProps<{
    fleet: NewsArticle[];
    featured: NewsArticle[];
    important: NewsArticle[];
    latest: NewsArticle[];
    sources: string[];
    ranked: boolean;
    checkedAt: string | null;
}>();

// Feeds refresh hourly; checking every five minutes picks up a new run soon after it lands.
usePoll(300_000);

const articles = computed(() => [
    ...new Map(
        [
            ...props.fleet,
            ...props.featured,
            ...props.important,
            ...props.latest,
        ].map((article) => [article.id, article]),
    ).values(),
]);

const query = ref('');
const source = ref<string | null>(null);

// Search covers the headline, the summary and the advisory ids, so pasting a CVE finds the stories about it.
function matches(article: NewsArticle): boolean {
    const needle = query.value.trim().toLowerCase();

    return (
        (source.value === null || article.source === source.value) &&
        (needle === '' ||
            [article.title, article.summary ?? '', ...article.advisory_ids]
                .join(' ')
                .toLowerCase()
                .includes(needle))
    );
}

const shown = computed(() => ({
    fleet: props.fleet.filter(matches),
    featured: props.featured.filter(matches),
    important: props.important.filter(matches),
    latest: props.latest.filter(matches),
}));

const filtering = computed(
    () => query.value.trim() !== '' || source.value !== null,
);
const nothingShown = computed(() =>
    Object.values(shown.value).every((list) => list.length === 0),
);

// Publisher images break now and then; fall back to the placeholder instead of a broken icon.
const brokenImages = reactive(new Set<number>());

function hasImage(article: NewsArticle): boolean {
    return article.image_url !== null && !brokenImages.has(article.id);
}

const card =
    'group relative flex flex-col overflow-hidden rounded-lg border bg-card shadow-card transition-[border-color] duration-200 focus-within:ring-[3px] focus-within:ring-ring/50 hover:border-ring/40';
</script>

<template>
    <Head title="Security news" />

    <div class="flex flex-1 flex-col gap-8 p-4 md:p-6">
        <header class="flex flex-col gap-1">
            <h1 class="text-[28px] font-bold tracking-tight lowercase">
                Security news
            </h1>
            <p class="max-w-3xl text-sm text-muted-foreground">
                From {{ sources.join(', ') }}, refreshed every hour. Articles
                naming a CVE or GHSA one of your services carries are pinned
                first.
                <template v-if="ranked">
                    Jev ranks the rest by how much they matter for the software
                    your services run.
                </template>
                <template v-if="checkedAt">
                    Last checked {{ lastSeen(checkedAt).toLowerCase() }}.
                </template>
            </p>
        </header>

        <NewsWire v-if="articles.length > 0" :articles="articles" />

        <section
            v-if="articles.length === 0"
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

        <div
            v-else
            class="flex flex-col gap-3 sm:flex-row sm:items-center"
            role="search"
        >
            <label class="relative sm:w-80">
                <span class="sr-only">Search news</span>
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    v-model="query"
                    type="search"
                    placeholder="Search headlines or a CVE…"
                    class="pl-8"
                />
            </label>
            <div
                class="flex flex-wrap gap-1.5"
                role="group"
                aria-label="Filter by source"
            >
                <button
                    v-for="name in [null, ...sources]"
                    :key="name ?? 'all'"
                    type="button"
                    class="rounded-full border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-accent"
                    :class="
                        source === name
                            ? 'border-foreground bg-foreground text-background hover:bg-foreground/90'
                            : 'text-muted-foreground'
                    "
                    :aria-pressed="source === name"
                    @click="source = name"
                >
                    {{ name ?? 'All sources' }}
                </button>
            </div>
        </div>

        <p
            v-if="articles.length > 0 && filtering && nothingShown"
            class="text-sm text-muted-foreground"
        >
            Nothing matches. Clear the search or pick another source.
        </p>

        <section
            v-if="shown.fleet.length"
            aria-labelledby="fleet-heading"
            class="flex flex-col gap-3"
        >
            <div class="flex flex-col gap-0.5">
                <h2
                    id="fleet-heading"
                    class="flex items-center gap-2 text-lg font-semibold"
                >
                    <Server
                        class="size-4 text-status-critical"
                        aria-hidden="true"
                    />
                    In your services
                </h2>
                <p class="text-sm text-muted-foreground">
                    These name an advisory a service of yours carries right now.
                </p>
            </div>
            <ul
                class="divide-y overflow-hidden rounded-lg border border-status-critical/40 bg-card shadow-card"
            >
                <li
                    v-for="article in shown.fleet"
                    :key="article.id"
                    class="relative flex flex-col gap-1.5 p-4 transition-colors focus-within:bg-accent/50 hover:bg-accent/50"
                >
                    <NewsMeta :article="article" ids />
                    <h3 class="leading-snug font-semibold">
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
                </li>
            </ul>
        </section>

        <section
            v-if="shown.featured.length"
            aria-labelledby="featured-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="featured-heading" class="text-lg font-semibold">
                Featured
            </h2>
            <div class="grid gap-4 lg:grid-cols-3 lg:grid-rows-2">
                <article
                    v-for="(article, index) in shown.featured"
                    :key="article.id"
                    :class="[
                        card,
                        { 'lg:col-span-2 lg:row-span-2': index === 0 },
                    ]"
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
                        <NewsMeta :article="article" :ids="index === 0" />
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
            v-if="shown.important.length"
            aria-labelledby="important-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="important-heading" class="text-lg font-semibold">
                Important
            </h2>
            <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                <article
                    v-for="article in shown.important"
                    :key="article.id"
                    :class="card"
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
                        <NewsMeta :article="article" />
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
            v-if="shown.latest.length"
            aria-labelledby="latest-heading"
            class="flex flex-col gap-3"
        >
            <h2 id="latest-heading" class="text-lg font-semibold">
                {{
                    shown.fleet.length ||
                    shown.featured.length ||
                    shown.important.length
                        ? 'Latest'
                        : 'All news'
                }}
            </h2>
            <ul
                class="divide-y overflow-hidden rounded-lg border bg-card shadow-card"
            >
                <li
                    v-for="article in shown.latest"
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
                        <NewsMeta :article="article" />
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
