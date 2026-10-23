<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { statusMeta } from '@/lib/status';
import type { Status } from '@/types';

defineProps<{
    status: Status;
}>();
</script>

<template>
    <Badge
        variant="outline"
        :class="['border-transparent', statusMeta[status].badge]"
    >
        <component :is="statusMeta[status].icon" aria-hidden="true" />
        {{ statusMeta[status].label }}
    </Badge>
</template>
