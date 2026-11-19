<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { EyeOff } from '@lucide/vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import { plural } from '@/lib/format';
import type { Coverage } from '@/types';

/** Blind spots: a tracker that has quietly stopped watching a service is a risk in itself. */
defineProps<{ coverage: Coverage }>();

const label = {
    silent: 'silent',
    unevaluated: 'not graded',
    unknown: 'unknown',
    expiring: 'expiring',
} as const;
</script>

<template>
    <p v-if="coverage.gaps.length === 0" class="text-xs text-muted-foreground">
        Coverage: all
        {{ plural(coverage.services, 'service', 'services') }} reporting and
        graded.
    </p>
    <section
        v-else
        aria-label="Coverage gaps"
        class="flex gap-2.5 rounded-md border border-dashed p-3 text-xs"
    >
        <EyeOff
            class="mt-0.5 size-4 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <ul class="flex flex-wrap gap-x-4 gap-y-1">
            <li
                v-for="(gap, i) in coverage.gaps"
                :key="i"
                class="flex items-baseline gap-1.5"
            >
                <span
                    class="font-mono text-[10px] text-muted-foreground uppercase"
                    >{{ label[gap.kind] }}</span
                >
                <Link
                    :href="ServiceController.show(gap.service.id)"
                    class="font-medium hover:underline"
                    >{{ gap.service.name }}</Link
                >
                <span class="text-muted-foreground">{{ gap.detail }}</span>
            </li>
        </ul>
    </section>
</template>
