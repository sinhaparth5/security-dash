<script setup lang="ts">
import { Head, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import { Clock, Search, ServerOff, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import ServiceSheet from '@/components/ServiceSheet.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { lastSeen, plural } from '@/lib/format';
import { statusMeta, statusOrder } from '@/lib/status';
import { dashboard } from '@/routes';
import type { MonitoredService, Status } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Service health',
                href: dashboard(),
            },
        ],
    },
});

const props = defineProps<{
    services: MonitoredService[];
}>();

const refreshedAt = ref(Date.now());

usePoll(60_000, {
    only: ['services'],
    onSuccess: () => (refreshedAt.value = Date.now()),
});

// Re-render relative times ("3 minutes ago") without waiting for the next poll.
const now = useNow({ interval: 30_000 });
const refreshedLabel = computed(() =>
    lastSeen(new Date(refreshedAt.value).toISOString(), now.value.getTime()),
);

const counts = computed(() =>
    props.services.reduce(
        (totals, service) => {
            totals[service.status]++;

            return totals;
        },
        { red: 0, yellow: 0, green: 0, unknown: 0 } as Record<Status, number>,
    ),
);

const statusFilter = ref<Status | null>(null);
const query = ref('');

function toggleFilter(status: Status): void {
    statusFilter.value = statusFilter.value === status ? null : status;
}

function clearFilters(): void {
    statusFilter.value = null;
    query.value = '';
}

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.services.filter(
        (service) =>
            (statusFilter.value === null ||
                service.status === statusFilter.value) &&
            (needle === '' ||
                service.name.toLowerCase().includes(needle) ||
                service.environment.toLowerCase().includes(needle)),
    );
});

// Track the selection by id so a poll refresh updates the open drawer in place.
const selectedId = ref<number | null>(null);
const selected = computed(
    () =>
        props.services.find((service) => service.id === selectedId.value) ??
        null,
);

function advisoryCount(service: MonitoredService): number {
    return service.dependencies.reduce(
        (total, dependency) => total + dependency.advisories.length,
        0,
    );
}

function dependencyBreakdown(service: MonitoredService) {
    const total = service.dependencies.length || 1;

    return statusOrder
        .map((status) => ({
            status,
            count: service.dependencies.filter(
                (dependency) => dependency.status === status,
            ).length,
        }))
        .filter((part) => part.count > 0)
        .map((part) => ({ ...part, width: `${(part.count / total) * 100}%` }));
}
</script>

<template>
    <Head title="Service health" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold tracking-tight">
                    Service health
                </h1>
                <p class="mt-1 text-sm text-muted-foreground">
                    Runtime and dependency versions your services report,
                    checked against OSV and upstream releases.
                </p>
            </div>
            <p
                class="flex items-center gap-1.5 text-xs text-muted-foreground"
                aria-live="polite"
            >
                <span class="relative flex size-2" aria-hidden="true">
                    <span
                        class="absolute inline-flex size-full animate-ping rounded-full bg-status-ok opacity-60 motion-reduce:hidden"
                    />
                    <span
                        class="relative inline-flex size-2 rounded-full bg-status-ok"
                    />
                </span>
                Live · refreshed {{ refreshedLabel.toLowerCase() }}
            </p>
        </header>

        <section
            aria-label="Status summary"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <button
                v-for="status in statusOrder"
                :key="status"
                type="button"
                :aria-pressed="statusFilter === status"
                class="group flex cursor-pointer flex-col rounded-xl border bg-card p-4 text-left shadow-xs transition-[border-color,box-shadow] duration-200 hover:border-ring/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-pressed:border-ring aria-pressed:ring-1 aria-pressed:ring-ring"
                @click="toggleFilter(status)"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{
                        statusMeta[status].label
                    }}</span>
                    <span
                        class="flex size-8 items-center justify-center rounded-lg"
                        :class="[
                            statusMeta[status].soft,
                            statusMeta[status].text,
                        ]"
                    >
                        <component
                            :is="statusMeta[status].icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                </div>
                <div class="mt-3 text-3xl font-semibold tabular-nums">
                    {{ counts[status] }}
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ statusMeta[status].description }}
                </p>
            </button>
        </section>

        <section
            v-if="services.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-xl border border-dashed p-10 text-center"
        >
            <ServerOff
                class="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 class="font-medium">No services are reporting yet</h2>
            <p class="max-w-md text-sm text-muted-foreground">
                Issue a token for a service, then have it post its dependency
                manifest to
                <code class="font-mono">/api/v1/telemetry/heartbeat</code>.
            </p>
            <code class="rounded-md bg-muted px-3 py-2 font-mono text-sm"
                >php artisan service:token &lt;name&gt;
                &lt;environment&gt;</code
            >
        </section>

        <section v-else aria-label="Services" class="flex flex-col gap-3">
            <div class="flex flex-wrap items-center gap-2">
                <div class="relative w-full sm:w-72">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <label for="service-search" class="sr-only"
                        >Search services</label
                    >
                    <Input
                        id="service-search"
                        v-model="query"
                        type="search"
                        placeholder="Search by service or environment"
                        class="pl-9"
                    />
                </div>
                <Badge
                    v-if="statusFilter"
                    variant="outline"
                    class="h-7 gap-1.5 px-2.5"
                >
                    Status: {{ statusMeta[statusFilter].label }}
                </Badge>
                <Button
                    v-if="statusFilter || query"
                    variant="ghost"
                    size="sm"
                    @click="clearFilters"
                >
                    <X aria-hidden="true" />
                    Clear filters
                </Button>
                <span class="ml-auto text-sm text-muted-foreground tabular-nums"
                    >{{ visible.length }} of
                    {{ plural(services.length, 'service', 'services') }}</span
                >
            </div>

            <div class="overflow-hidden rounded-xl border bg-card shadow-xs">
                <Table>
                    <TableHeader>
                        <TableRow class="hover:bg-transparent">
                            <TableHead class="pl-4">Service</TableHead>
                            <TableHead>Status</TableHead>
                            <TableHead class="hidden md:table-cell"
                                >Dependencies</TableHead
                            >
                            <TableHead class="hidden text-right sm:table-cell"
                                >Advisories</TableHead
                            >
                            <TableHead
                                class="hidden pr-4 text-right lg:table-cell"
                                >Last report</TableHead
                            >
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="service in visible"
                            :key="service.id"
                            class="cursor-pointer"
                            @click="selectedId = service.id"
                        >
                            <TableCell class="py-3 pl-4">
                                <!-- The button gives keyboard and screen-reader users the same entry point as a row click. -->
                                <button
                                    type="button"
                                    class="cursor-pointer rounded-sm text-left font-medium focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    @click.stop="selectedId = service.id"
                                >
                                    {{ service.name }}
                                </button>
                                <div
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ service.environment }}
                                </div>
                            </TableCell>
                            <TableCell>
                                <StatusBadge :status="service.status" />
                            </TableCell>
                            <TableCell class="hidden md:table-cell">
                                <div class="flex w-40 flex-col gap-1.5">
                                    <div
                                        class="flex h-1.5 overflow-hidden rounded-full bg-muted"
                                        role="img"
                                        :aria-label="
                                            dependencyBreakdown(service)
                                                .map(
                                                    (part) =>
                                                        `${part.count} ${statusMeta[part.status].label.toLowerCase()}`,
                                                )
                                                .join(', ') || 'No dependencies'
                                        "
                                    >
                                        <span
                                            v-for="part in dependencyBreakdown(
                                                service,
                                            )"
                                            :key="part.status"
                                            :class="
                                                statusMeta[part.status].badge
                                            "
                                            :style="{ width: part.width }"
                                        />
                                    </div>
                                    <span
                                        class="text-xs text-muted-foreground tabular-nums"
                                        >{{
                                            plural(
                                                service.dependencies.length,
                                                'dependency',
                                                'dependencies',
                                            )
                                        }}</span
                                    >
                                </div>
                            </TableCell>
                            <TableCell
                                class="hidden text-right tabular-nums sm:table-cell"
                            >
                                <span
                                    :class="
                                        advisoryCount(service) > 0
                                            ? 'font-semibold text-status-critical'
                                            : 'text-muted-foreground'
                                    "
                                    >{{ advisoryCount(service) }}</span
                                >
                            </TableCell>
                            <TableCell
                                class="hidden pr-4 text-right text-sm lg:table-cell"
                            >
                                <span
                                    v-if="service.is_stale"
                                    class="inline-flex items-center gap-1 font-medium text-status-warning-foreground dark:text-status-warning"
                                >
                                    <Clock
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Stale · {{ lastSeen(service.last_ping_at) }}
                                </span>
                                <span
                                    v-else
                                    class="text-muted-foreground tabular-nums"
                                    >{{ lastSeen(service.last_ping_at) }}</span
                                >
                            </TableCell>
                        </TableRow>
                        <TableRow v-if="visible.length === 0">
                            <TableCell
                                colspan="5"
                                class="h-32 text-center text-sm text-muted-foreground"
                            >
                                No services match these filters.
                                <Button
                                    variant="link"
                                    class="px-1"
                                    @click="clearFilters"
                                    >Clear filters</Button
                                >
                            </TableCell>
                        </TableRow>
                    </TableBody>
                </Table>
            </div>
        </section>

        <ServiceSheet :service="selected" @close="selectedId = null" />
    </div>
</template>
