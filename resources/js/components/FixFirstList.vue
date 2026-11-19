<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import type { FixFirstItem } from '@/types';

/**
 * Open advisories in the order to fix them: malware, then known exploited, then EPSS and severity,
 * discounted when the code never reaches them and outside production. Ordering only, never a grade.
 */
defineProps<{ items: FixFirstItem[] }>();
</script>

<template>
    <p v-if="items.length === 0" class="text-xs text-muted-foreground">
        No open advisories.
    </p>
    <ol v-else class="flex flex-col gap-1.5 text-xs">
        <li
            v-for="item in items"
            :key="`${item.service.id}:${item.package}:${item.osv_id}`"
            class="flex flex-col"
        >
            <span class="flex items-baseline justify-between gap-2">
                <Link
                    :href="ServiceController.show(item.service.id)"
                    class="min-w-0 truncate font-medium hover:underline"
                >
                    {{ item.package }}
                    <span class="font-normal text-muted-foreground">
                        {{ item.version
                        }}{{
                            item.fixed_version ? ` → ${item.fixed_version}` : ''
                        }}
                        · {{ item.service.name }}
                    </span>
                </Link>
                <span class="shrink-0 font-mono text-muted-foreground">{{
                    item.osv_id
                }}</span>
            </span>
            <span class="text-muted-foreground">{{
                item.reasons.join(' · ')
            }}</span>
        </li>
    </ol>
</template>
