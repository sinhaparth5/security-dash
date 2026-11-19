<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Flame, Server, Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { lastSeen } from '@/lib/format';
import type { NewsArticle } from '@/types';

/**
 * The line above a headline (Jev's rank, KEV, source, age) and, under it, the advisories the article names
 * and the services that carry them. Links sit above the card's full-size headline link (z-10) so they stay clickable.
 */
const props = defineProps<{ article: NewsArticle; ids?: boolean }>();

const rank = computed(() => {
    const { importance, importance_confidence } = props.article;

    if (importance === null || importance < 1.5) {
        return null;
    }

    const title = `Ranked by Jev for the software your services run (confidence ${Math.round((importance_confidence ?? 0) * 100)}%). A suggestion, not a grade.`;

    return importance >= 2.5
        ? {
              label: 'Act now',
              class: 'bg-status-critical text-status-critical-foreground',
              title,
          }
        : {
              label: 'Important',
              class: 'bg-status-warning text-status-warning-foreground',
              title,
          };
});

// ponytail: six ids per card; a roundup naming dozens shows a count for the rest.
const shownIds = computed(() => props.article.advisory_ids.slice(0, 6));
</script>

<template>
    <div
        class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground"
    >
        <span
            v-if="article.exploited"
            class="inline-flex items-center gap-1 rounded-md bg-status-critical px-1.5 py-0.5 font-medium text-status-critical-foreground"
            title="A CVE in this article is in CISA's Known Exploited Vulnerabilities catalog: attackers are using it now."
        >
            <Flame class="size-3" aria-hidden="true" />
            Exploited
        </span>
        <span
            v-if="rank"
            class="inline-flex items-center gap-1 rounded-md px-1.5 py-0.5 font-medium"
            :class="rank.class"
            :title="rank.title"
        >
            <Sparkles class="size-3" aria-hidden="true" />
            {{ rank.label }}
        </span>
        <span class="font-medium text-foreground">{{ article.source }}</span>
        <time :datetime="article.published_at">{{
            lastSeen(article.published_at)
        }}</time>
    </div>
    <div
        v-if="article.affects.length || (ids && shownIds.length)"
        class="relative z-10 flex flex-wrap items-center gap-1.5 text-xs"
    >
        <Link
            v-for="service in article.affects"
            :key="service.id"
            :href="ServiceController.show(service.id)"
            class="inline-flex items-center gap-1 rounded-md border border-status-critical/40 bg-status-critical-soft px-1.5 py-0.5 font-medium hover:underline"
        >
            <Server class="size-3" aria-hidden="true" />
            {{ service.name }}
            <span class="text-muted-foreground">{{ service.environment }}</span>
        </Link>
        <template v-if="ids">
            <a
                v-for="id in shownIds"
                :key="id"
                :href="`https://osv.dev/vulnerability/${id}`"
                target="_blank"
                rel="noopener noreferrer"
                class="rounded-md border px-1.5 py-0.5 font-mono text-muted-foreground hover:text-foreground hover:underline"
                >{{ id }}<span class="sr-only"> (opens in a new tab)</span></a
            >
            <span
                v-if="article.advisory_ids.length > shownIds.length"
                class="text-muted-foreground"
                >+{{ article.advisory_ids.length - shownIds.length }} more</span
            >
        </template>
    </div>
</template>
