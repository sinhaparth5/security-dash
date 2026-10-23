<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { statusMeta } from '@/lib/status';
import type { StatusHistory } from '@/types';

const props = defineProps<{
    events: StatusHistory['events'];
}>();

const today = new Date();
const monthLabel = new Intl.DateTimeFormat(undefined, {
    month: 'long',
    year: 'numeric',
}).format(today);
const weekdays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

function dayKey(date: Date): string {
    return `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}`;
}

// Monday-first weeks covering the current month, padded with the neighbouring months' days.
const cells = computed(() => {
    const first = new Date(today.getFullYear(), today.getMonth(), 1);
    const start = new Date(first);
    start.setDate(1 - ((first.getDay() + 6) % 7));
    const last = new Date(today.getFullYear(), today.getMonth() + 1, 0);
    const count =
        Math.ceil((((first.getDay() + 6) % 7) + last.getDate()) / 7) * 7;

    const byDay = new Map<string, StatusHistory['events']>();

    for (const event of props.events) {
        const key = dayKey(new Date(event.at));
        byDay.set(key, [...(byDay.get(key) ?? []), event]);
    }

    return Array.from({ length: count }, (_, index) => {
        const date = new Date(start);
        date.setDate(start.getDate() + index);

        return {
            date,
            inMonth: date.getMonth() === today.getMonth(),
            isToday: dayKey(date) === dayKey(today),
            events: byDay.get(dayKey(date)) ?? [],
        };
    });
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <p class="text-sm font-semibold">{{ monthLabel }}</p>
        <div class="grid grid-cols-7 overflow-hidden rounded-md border text-xs">
            <div
                v-for="weekday in weekdays"
                :key="weekday"
                aria-hidden="true"
                class="border-b bg-secondary px-2 py-1 text-center text-muted-foreground"
            >
                {{ weekday }}
            </div>
            <div
                v-for="(cell, index) in cells"
                :key="index"
                class="flex min-h-20 min-w-0 flex-col gap-1 border-b p-1.5"
                :class="[
                    index % 7 !== 6 ? 'border-r' : '',
                    cell.inMonth ? '' : 'bg-muted text-muted-foreground/70',
                ]"
            >
                <span
                    class="flex size-6 items-center justify-center self-end rounded-full tabular-nums"
                    :class="
                        cell.isToday
                            ? 'bg-brand font-semibold text-brand-foreground'
                            : ''
                    "
                    :aria-current="cell.isToday ? 'date' : undefined"
                    >{{ cell.date.getDate() }}</span
                >
                <Link
                    v-for="event in cell.events.slice(0, 3)"
                    :key="`${event.service_id}-${event.at}`"
                    :href="ServiceController.show(event.service_id)"
                    class="flex min-w-0 items-center gap-1 rounded-sm border px-1 py-0.5 shadow-card transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                    :class="statusMeta[event.status].soft"
                    :title="`${event.service} → ${statusMeta[event.status].label.toLowerCase()}`"
                >
                    <component
                        :is="statusMeta[event.status].icon"
                        class="size-3 shrink-0"
                        :class="statusMeta[event.status].text"
                        aria-hidden="true"
                    />
                    <span class="truncate max-sm:sr-only">{{
                        event.service
                    }}</span>
                    <span class="sr-only"
                        >turned
                        {{ statusMeta[event.status].label.toLowerCase() }}</span
                    >
                </Link>
                <span
                    v-if="cell.events.length > 3"
                    class="px-1 text-muted-foreground"
                    >+{{ cell.events.length - 3 }} more</span
                >
            </div>
        </div>
    </div>
</template>
