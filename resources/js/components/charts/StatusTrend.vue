<script setup lang="ts">
import { computed, ref } from 'vue';
import { statusMeta, statusOrder } from '@/lib/status';
import type { StatusHistory } from '@/types';

const props = defineProps<{
    trend: StatusHistory['trend'];
}>();

const dayFormat = new Intl.DateTimeFormat(undefined, {
    month: 'short',
    day: 'numeric',
});

const days = computed(() =>
    props.trend.map((day) => ({
        ...day,
        label: dayFormat.format(new Date(`${day.date}T12:00:00`)),
        total: statusOrder.reduce((sum, status) => sum + day[status], 0),
    })),
);

const max = computed(() => Math.max(1, ...days.value.map((day) => day.total)));

const active = ref<number | null>(null);

function describe(day: (typeof days.value)[number]): string {
    return `${day.label}: ${
        statusOrder
            .filter((status) => day[status] > 0)
            .map(
                (status) =>
                    `${day[status]} ${statusMeta[status].label.toLowerCase()}`,
            )
            .join(', ') || 'no services'
    }`;
}
</script>

<template>
    <div class="flex flex-col gap-3">
        <ul
            class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground"
        >
            <li
                v-for="status in statusOrder"
                :key="status"
                class="flex items-center gap-1.5"
            >
                <span
                    class="size-2.5 rounded-[2px]"
                    :class="statusMeta[status].fill"
                    aria-hidden="true"
                />
                <span class="lowercase">{{ statusMeta[status].label }}</span>
            </li>
        </ul>

        <div class="relative flex h-40 gap-2">
            <!-- Recessive y axis: just the top and bottom ticks. -->
            <div
                class="flex flex-col justify-between pb-5 text-right text-[11px] text-muted-foreground tabular-nums"
                aria-hidden="true"
            >
                <span>{{ max }}</span>
                <span>0</span>
            </div>
            <div class="relative flex flex-1 flex-col">
                <div
                    class="pointer-events-none absolute inset-x-0 top-0 bottom-5 flex flex-col justify-between"
                    aria-hidden="true"
                >
                    <span class="border-t border-dashed" />
                    <span class="border-t border-dashed" />
                    <span class="border-t" />
                </div>
                <ol class="relative flex flex-1 items-end gap-[2px] pb-5">
                    <li
                        v-for="(day, index) in days"
                        :key="day.date"
                        tabindex="0"
                        :aria-label="describe(day)"
                        class="group relative flex h-full flex-1 cursor-default flex-col justify-end rounded-t-sm outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
                        @mouseenter="active = index"
                        @mouseleave="active = null"
                        @focus="active = index"
                        @blur="active = null"
                    >
                        <div
                            class="flex flex-col gap-[2px] overflow-hidden rounded-t-[4px] transition-opacity duration-150"
                            :class="
                                active !== null && active !== index
                                    ? 'opacity-50'
                                    : ''
                            "
                            :style="{ height: `${(day.total / max) * 100}%` }"
                        >
                            <span
                                v-for="status in statusOrder"
                                v-show="day[status] > 0"
                                :key="status"
                                :class="statusMeta[status].fill"
                                :style="{ flexGrow: day[status] }"
                            />
                        </div>
                        <span
                            v-if="(days.length - 1 - index) % 7 === 0"
                            class="absolute -bottom-5 left-1/2 -translate-x-1/2 text-[11px] whitespace-nowrap text-muted-foreground"
                            aria-hidden="true"
                            >{{ day.label }}</span
                        >
                    </li>
                </ol>
                <div
                    v-if="active !== null"
                    role="status"
                    class="pointer-events-none absolute -top-2 z-10 min-w-36 -translate-y-full rounded-md border bg-popover p-2 text-xs shadow-md"
                    :style="{
                        left: `${Math.min(Math.max((active / days.length) * 100, 10), 75)}%`,
                    }"
                >
                    <p class="mb-1 font-semibold">{{ days[active].label }}</p>
                    <p
                        v-for="status in statusOrder"
                        :key="status"
                        class="flex items-center gap-1.5"
                    >
                        <component
                            :is="statusMeta[status].icon"
                            class="size-3"
                            :class="statusMeta[status].text"
                            aria-hidden="true"
                        />
                        <span class="flex-1 lowercase">{{
                            statusMeta[status].label
                        }}</span>
                        <span class="tabular-nums">{{
                            days[active][status]
                        }}</span>
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>
