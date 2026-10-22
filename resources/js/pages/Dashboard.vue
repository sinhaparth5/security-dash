<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { dashboard } from '@/routes';
import type { MonitoredService, Status } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Dashboard',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    services: MonitoredService[];
}>();

usePoll(60_000, { only: ['services'] });

const summary: { status: Status; label: string }[] = [
    { status: 'red', label: 'Need a security patch' },
    { status: 'yellow', label: 'Have updates available' },
    { status: 'green', label: 'Up to date' },
    { status: 'unknown', label: 'Not yet evaluated' },
];

const counts = computed(() =>
    props.services.reduce(
        (totals, service) => {
            totals[service.status]++;

            return totals;
        },
        { red: 0, yellow: 0, green: 0, unknown: 0 } as Record<Status, number>,
    ),
);

// Track the selection by id so a poll refresh updates the open drawer in place.
const selectedId = ref<number | null>(null);
const selected = computed(
    () =>
        props.services.find((service) => service.id === selectedId.value) ??
        null,
);

const relativeTime = new Intl.RelativeTimeFormat(undefined, {
    numeric: 'auto',
});

function lastSeen(iso: string | null): string {
    if (iso === null) {
        return 'Never reported';
    }

    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000);

    if (Math.abs(minutes) < 60) {
        return relativeTime.format(minutes, 'minute');
    }

    if (Math.abs(minutes) < 60 * 24) {
        return relativeTime.format(Math.round(minutes / 60), 'hour');
    }

    return relativeTime.format(Math.round(minutes / (60 * 24)), 'day');
}

function plural(count: number, singular: string, pluralForm: string): string {
    return `${count} ${count === 1 ? singular : pluralForm}`;
}

function advisoryCount(service: MonitoredService): number {
    return service.dependencies.reduce(
        (total, dependency) => total + dependency.advisories.length,
        0,
    );
}

function advisoryUrl(osvId: string): string | null {
    return osvId.startsWith('NODE-')
        ? null
        : `https://osv.dev/vulnerability/${encodeURIComponent(osvId)}`;
}
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
        <div class="grid grid-cols-2 gap-4 lg:grid-cols-4">
            <div
                v-for="item in summary"
                :key="item.status"
                class="rounded-xl border border-sidebar-border/70 p-4 dark:border-sidebar-border"
            >
                <div class="text-3xl font-semibold tabular-nums">
                    {{ counts[item.status] }}
                </div>
                <div class="mt-2 flex flex-wrap items-center gap-2">
                    <StatusBadge :status="item.status" />
                    <span class="text-sm text-muted-foreground">
                        {{ item.label }}
                    </span>
                </div>
            </div>
        </div>

        <div
            class="flex-1 overflow-hidden rounded-xl border border-sidebar-border/70 dark:border-sidebar-border"
        >
            <div
                v-if="services.length === 0"
                class="flex h-full min-h-64 flex-col items-center justify-center gap-2 p-8 text-center"
            >
                <p class="font-medium">No services are reporting yet.</p>
                <p class="text-sm text-muted-foreground">
                    Issue a token with
                    <code class="rounded bg-muted px-1.5 py-0.5"
                        >php artisan service:token &lt;name&gt;
                        &lt;environment&gt;</code
                    >
                    and send a heartbeat.
                </p>
            </div>

            <ul v-else class="divide-y divide-sidebar-border/70">
                <li v-for="service in services" :key="service.id">
                    <button
                        type="button"
                        class="flex w-full flex-col gap-2 px-4 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none sm:flex-row sm:items-center sm:gap-4"
                        @click="selectedId = service.id"
                    >
                        <div class="min-w-0 flex-1">
                            <div class="truncate font-medium">
                                {{ service.name }}
                            </div>
                            <div class="text-sm text-muted-foreground">
                                {{ service.environment }} ·
                                {{
                                    plural(
                                        service.dependencies.length,
                                        'dependency',
                                        'dependencies',
                                    )
                                }}
                                <template v-if="advisoryCount(service) > 0">
                                    ·
                                    {{
                                        plural(
                                            advisoryCount(service),
                                            'advisory',
                                            'advisories',
                                        )
                                    }}
                                </template>
                            </div>
                        </div>
                        <div
                            class="text-sm tabular-nums"
                            :class="
                                service.is_stale
                                    ? 'font-medium text-amber-600 dark:text-amber-400'
                                    : 'text-muted-foreground'
                            "
                        >
                            <template
                                v-if="service.is_stale && service.last_ping_at"
                                >Stale ·
                            </template>
                            {{ lastSeen(service.last_ping_at) }}
                        </div>
                        <StatusBadge :status="service.status" />
                    </button>
                </li>
            </ul>
        </div>

        <Sheet
            :open="selected !== null"
            @update:open="(open) => !open && (selectedId = null)"
        >
            <SheetContent class="w-full overflow-y-auto sm:max-w-xl">
                <template v-if="selected">
                    <SheetHeader>
                        <SheetTitle>{{ selected.name }}</SheetTitle>
                        <SheetDescription>
                            {{ selected.environment }} · last report
                            {{ lastSeen(selected.last_ping_at).toLowerCase() }}
                        </SheetDescription>
                        <StatusBadge :status="selected.status" />
                    </SheetHeader>

                    <p
                        v-if="selected.dependencies.length === 0"
                        class="px-4 text-sm text-muted-foreground"
                    >
                        This service has not reported any dependencies.
                    </p>

                    <ul class="space-y-3 px-4 pb-4">
                        <li
                            v-for="dependency in selected.dependencies"
                            :key="dependency.id"
                            class="rounded-lg border border-sidebar-border/70 p-3 dark:border-sidebar-border"
                        >
                            <div
                                class="flex flex-wrap items-start justify-between gap-2"
                            >
                                <div class="min-w-0">
                                    <div class="font-medium break-all">
                                        {{ dependency.name }}
                                    </div>
                                    <div
                                        class="text-sm text-muted-foreground tabular-nums"
                                    >
                                        {{ dependency.ecosystem }} ·
                                        {{ dependency.version }}
                                        <template
                                            v-if="
                                                dependency.latest_version &&
                                                dependency.latest_version !==
                                                    dependency.version
                                            "
                                        >
                                            → {{ dependency.latest_version }}
                                        </template>
                                    </div>
                                </div>
                                <StatusBadge :status="dependency.status" />
                            </div>

                            <ul
                                v-if="dependency.advisories.length > 0"
                                class="mt-3 space-y-2"
                            >
                                <li
                                    v-for="advisory in dependency.advisories"
                                    :key="advisory.id"
                                    class="rounded-md bg-red-50 p-2 text-sm dark:bg-red-950/40"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-x-2"
                                    >
                                        <a
                                            v-if="advisoryUrl(advisory.osv_id)"
                                            :href="
                                                advisoryUrl(advisory.osv_id)!
                                            "
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="font-medium underline underline-offset-2"
                                            >{{ advisory.osv_id }}</a
                                        >
                                        <span v-else class="font-medium">{{
                                            advisory.osv_id
                                        }}</span>
                                        <span
                                            v-if="advisory.aliases.length"
                                            class="text-muted-foreground"
                                            >{{
                                                advisory.aliases.join(', ')
                                            }}</span
                                        >
                                        <span
                                            v-if="advisory.severity"
                                            class="text-xs font-semibold uppercase"
                                            >{{ advisory.severity }}</span
                                        >
                                    </div>
                                    <p v-if="advisory.summary" class="mt-1">
                                        {{ advisory.summary }}
                                    </p>
                                    <p
                                        v-if="advisory.fixed_version"
                                        class="mt-1 text-muted-foreground"
                                    >
                                        Fixed in {{ advisory.fixed_version }}
                                    </p>
                                </li>
                            </ul>
                        </li>
                    </ul>
                </template>
            </SheetContent>
        </Sheet>
    </div>
</template>
