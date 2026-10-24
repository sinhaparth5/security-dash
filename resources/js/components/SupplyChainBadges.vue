<script setup lang="ts">
import {
    BadgeAlert,
    FileExclamationPoint,
    Timer,
    UserRoundPen,
} from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import type { SupplyChainSignal } from '@/types';

/**
 * Release signals on an npm package: not vulnerabilities, but how supply-chain attacks look from outside.
 */
defineProps<{ signals: SupplyChainSignal[] }>();

const meta = {
    fresh: { label: 'New release', icon: Timer },
    install_script: { label: 'Install script', icon: FileExclamationPoint },
    provenance_dropped: { label: 'Provenance dropped', icon: BadgeAlert },
    new_publisher: { label: 'New publisher', icon: UserRoundPen },
};
</script>

<template>
    <Badge
        v-for="signal in signals"
        :key="signal.signal"
        variant="outline"
        class="gap-1 font-normal text-status-warning-foreground dark:text-status-warning"
        :title="signal.detail"
    >
        <component :is="meta[signal.signal].icon" aria-hidden="true" />
        {{ meta[signal.signal].label }}
        <span class="sr-only">: {{ signal.detail }}</span>
    </Badge>
</template>
