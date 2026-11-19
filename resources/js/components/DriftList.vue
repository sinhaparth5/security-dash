<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { plural } from '@/lib/format';
import type { Drift } from '@/types';

/**
 * Services furthest behind their dependencies' latest releases: end of life first, then majors behind.
 * An early warning only; it never changes a grade.
 */
defineProps<{ drift: Drift }>();

const signed = (value: number) => (value > 0 ? `+${value}` : `${value}`);
</script>

<template>
    <p v-if="drift.services.length === 0" class="text-xs text-muted-foreground">
        Every tracked dependency is on its latest release.
    </p>
    <template v-else>
        <ol class="flex flex-col gap-1.5 text-xs">
            <li
                v-for="service in drift.services"
                :key="service.id"
                class="flex items-baseline justify-between gap-2"
            >
                <Link
                    :href="ServiceController.show(service.id)"
                    class="min-w-0 truncate font-medium hover:underline"
                >
                    {{ service.name }}
                    <span class="font-normal text-muted-foreground">{{
                        service.environment
                    }}</span>
                </Link>
                <span
                    class="shrink-0 text-right text-muted-foreground tabular-nums"
                >
                    <span
                        v-if="service.end_of_life > 0"
                        class="font-medium text-status-critical"
                        >{{ service.end_of_life }} end of life ·
                    </span>
                    {{ plural(service.majors_behind, 'major', 'majors') }}
                    behind<span
                        v-if="service.majors_change"
                        :class="
                            service.majors_change > 0
                                ? 'text-status-warning'
                                : 'text-status-ok'
                        "
                    >
                        ({{ signed(service.majors_change) }})</span
                    >
                    · {{ service.outdated }}/{{ service.dependencies }} outdated
                </span>
            </li>
        </ol>
        <p class="mt-2 text-xs text-muted-foreground tabular-nums">
            Fleet: {{ drift.totals.outdated }} outdated,
            {{ drift.totals.majors_behind }} majors behind,
            {{ drift.totals.end_of_life }} past end of life{{
                drift.since ? `; changes since ${drift.since}` : ''
            }}.
        </p>
    </template>
</template>
