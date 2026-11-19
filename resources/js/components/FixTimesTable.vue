<script setup lang="ts">
import type { FixTimeRow, FixTimes } from '@/types';

/**
 * Days from first sighting to fix, by severity then environment. "On time" is the share fixed
 * inside the deadline the patch queue uses (malware 1 day, critical 2, high 7, moderate 30, low 90).
 */
defineProps<{ fixTimes: FixTimes }>();

const days = (value: number) => (value < 1 ? '<1d' : `${value}d`);
const onTimeClass = (row: FixTimeRow) =>
    row.on_time >= 90
        ? 'text-status-ok'
        : row.on_time >= 70
          ? 'text-status-warning'
          : 'text-status-critical';
</script>

<template>
    <p
        v-if="fixTimes.severity.length === 0"
        class="text-xs text-muted-foreground"
    >
        No fixes recorded in the last {{ fixTimes.window_days }} days.
    </p>
    <table v-else class="w-full text-xs tabular-nums">
        <caption class="sr-only">
            Days to fix advisories over the last
            {{
                fixTimes.window_days
            }}
            days
        </caption>
        <thead class="text-muted-foreground">
            <tr>
                <th scope="col" class="pb-1 text-left font-normal"></th>
                <th scope="col" class="pb-1 text-right font-normal">fixed</th>
                <th scope="col" class="pb-1 text-right font-normal">median</th>
                <th scope="col" class="pb-1 text-right font-normal">p90</th>
                <th scope="col" class="pb-1 text-right font-normal">on time</th>
            </tr>
        </thead>
        <template
            v-for="(rows, group) in {
                severity: fixTimes.severity,
                environment: fixTimes.environment,
            }"
            :key="group"
        >
            <tbody class="border-t">
                <tr v-for="row in rows" :key="row.label">
                    <th
                        scope="row"
                        class="py-1 text-left font-medium lowercase"
                    >
                        {{ row.label }}
                    </th>
                    <td class="py-1 text-right">{{ row.fixed }}</td>
                    <td class="py-1 text-right">{{ days(row.median_days) }}</td>
                    <td class="py-1 text-right">{{ days(row.p90_days) }}</td>
                    <td
                        class="py-1 text-right font-medium"
                        :class="onTimeClass(row)"
                    >
                        {{ row.on_time }}%
                    </td>
                </tr>
            </tbody>
        </template>
    </table>
</template>
