<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import type { Status } from '@/types';

defineProps<{
    status: Status;
}>();

const labels: Record<Status, string> = {
    red: 'Security patch required',
    yellow: 'Update available',
    green: 'Up to date',
    unknown: 'Unknown',
};

const classes: Record<Status, string> = {
    red: 'border-transparent bg-red-600 text-white dark:bg-red-500/80',
    yellow: 'border-transparent bg-amber-400 text-amber-950 dark:bg-amber-400/80',
    green: 'border-transparent bg-emerald-600 text-white dark:bg-emerald-500/80',
    unknown: 'text-muted-foreground',
};
</script>

<template>
    <Badge
        :variant="status === 'unknown' ? 'outline' : 'default'"
        :class="classes[status]"
    >
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ labels[status] }}
    </Badge>
</template>
