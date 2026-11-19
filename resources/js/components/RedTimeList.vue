<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { plural } from '@/lib/format';
import type { RedTime } from '@/types';

/** The services that sat Red longest over the window, and the totals per environment. */
defineProps<{ redTime: RedTime }>();

const days = (value: number) => (value < 1 ? '<1d' : `${value}d`);
</script>

<template>
    <p
        v-if="redTime.services.length === 0"
        class="text-xs text-muted-foreground"
    >
        No service was Red in the last {{ redTime.window_days }} days.
    </p>
    <template v-else>
        <ol class="flex flex-col gap-1.5 text-xs">
            <li
                v-for="service in redTime.services"
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
                    :title="
                        service.median_recovery_days === null
                            ? undefined
                            : `Median ${days(service.median_recovery_days)} to recover`
                    "
                >
                    <span
                        class="font-medium"
                        :class="
                            service.red_now
                                ? 'text-status-critical'
                                : 'text-foreground'
                        "
                        >{{ days(service.red_days) }}</span
                    >
                    · {{ plural(service.times_red, 'time', 'times') }}
                    <span v-if="service.red_now" class="text-status-critical"
                        >· red now</span
                    >
                </span>
            </li>
        </ol>
        <p class="border-t pt-2 text-xs text-muted-foreground tabular-nums">
            <span v-for="(env, i) in redTime.environment" :key="env.label"
                >{{ i ? ' · ' : '' }}{{ env.label }}:
                {{ days(env.red_days) }} across
                {{ plural(env.services, 'service', 'services') }}</span
            >
        </p>
    </template>
</template>
