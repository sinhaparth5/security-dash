<script setup lang="ts">
import type { LucideIcon } from '@lucide/vue';

defineProps<{
    title: string;
    icon: LucideIcon;
}>();
</script>

<template>
    <!-- A Notion-style block: flat card, small lowercase mono heading with an icon. -->
    <section
        class="flex flex-col gap-3 rounded-lg border bg-card p-5 shadow-card"
    >
        <header class="flex items-center justify-between gap-2">
            <h2 class="flex items-center gap-2 text-base font-bold lowercase">
                <component
                    :is="icon"
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                {{ title }}
            </h2>
            <slot name="action" />
        </header>
        <slot />
    </section>
</template>
