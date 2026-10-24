<script setup lang="ts">
import { computed } from 'vue';
import { describeStatusCounts, statusMeta, statusOrder } from '@/lib/status';
import type { Status } from '@/types';

const props = defineProps<{
    counts: Record<Status, number>;
}>();

const filter = defineModel<Status | null>({ default: null });

const radius = 42;
const circumference = 2 * Math.PI * radius;
// A 2px surface gap between slices (see the dataviz mark specs), in stroke units.
const gap = 2;

const total = computed(() =>
    statusOrder.reduce((sum, status) => sum + props.counts[status], 0),
);

const slices = computed(() => {
    let offset = 0;

    return statusOrder
        .filter((status) => props.counts[status] > 0)
        .map((status) => {
            const length = (props.counts[status] / total.value) * circumference;
            const slice = {
                status,
                dash: `${Math.max(length - (total.value === props.counts[status] ? 0 : gap), 0.5)} ${circumference}`,
                offset: -offset,
            };
            offset += length;

            return slice;
        });
});

function percent(status: Status): number {
    return total.value
        ? Math.round((props.counts[status] / total.value) * 100)
        : 0;
}
</script>

<template>
    <div class="flex flex-col items-center gap-4">
        <div class="relative size-36">
            <svg
                viewBox="0 0 100 100"
                class="size-full -rotate-90"
                role="img"
                :aria-label="describeStatusCounts(counts)"
            >
                <circle
                    cx="50"
                    cy="50"
                    :r="radius"
                    fill="none"
                    class="stroke-muted"
                    stroke-width="12"
                />
                <circle
                    v-for="slice in slices"
                    :key="slice.status"
                    cx="50"
                    cy="50"
                    :r="radius"
                    fill="none"
                    stroke-width="12"
                    :stroke-dasharray="slice.dash"
                    :stroke-dashoffset="slice.offset"
                    class="transition-opacity duration-200"
                    :class="[
                        statusMeta[slice.status].stroke,
                        filter && filter !== slice.status ? 'opacity-25' : '',
                    ]"
                >
                    <title>
                        {{ counts[slice.status] }}
                        {{ statusMeta[slice.status].label.toLowerCase() }}
                    </title>
                </circle>
            </svg>
            <div
                class="absolute inset-0 flex flex-col items-center justify-center"
            >
                <span class="text-2xl font-semibold tabular-nums">{{
                    total
                }}</span>
                <span class="text-xs text-muted-foreground">services</span>
            </div>
        </div>

        <!-- The legend doubles as the status filter, and carries icon, label and count so colour is never alone. -->
        <ul class="flex w-full flex-col gap-0.5">
            <li v-for="status in statusOrder" :key="status">
                <button
                    type="button"
                    :aria-pressed="filter === status"
                    class="flex w-full cursor-pointer items-center gap-2 rounded-sm px-2 py-1.5 text-left text-xs transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-pressed:bg-accent aria-pressed:font-semibold"
                    @click="filter = filter === status ? null : status"
                >
                    <component
                        :is="statusMeta[status].icon"
                        class="size-3.5 shrink-0"
                        :class="statusMeta[status].text"
                        aria-hidden="true"
                    />
                    <span class="flex-1 lowercase">{{
                        statusMeta[status].label
                    }}</span>
                    <span class="tabular-nums">{{ counts[status] }}</span>
                    <span
                        class="w-9 text-right text-muted-foreground tabular-nums"
                        >{{ percent(status) }}%</span
                    >
                </button>
            </li>
        </ul>
    </div>
</template>
