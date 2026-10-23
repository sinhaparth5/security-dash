<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <!-- Patchwatch mark: a shield with a heartbeat trace, for services that report in. -->
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 24 24"
        fill="none"
        stroke="currentColor"
        stroke-width="2"
        stroke-linecap="round"
        stroke-linejoin="round"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M12 2.5 4.5 5.5v5.8c0 4.7 3.1 8.6 7.5 10.2 4.4-1.6 7.5-5.5 7.5-10.2V5.5L12 2.5Z"
        />
        <path d="M7.5 12.5h2.2l1.4-3 2 6 1.4-3h2" />
    </svg>
</template>
