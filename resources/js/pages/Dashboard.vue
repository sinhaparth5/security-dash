<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import {
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    CalendarClock,
    Clock,
    Plus,
    Search,
    ServerOff,
    Skull,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import RegisterServiceSheet from '@/components/RegisterServiceSheet.vue';
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
import type { MonitoredService, Pipeline, Status } from '@/types';

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
    pipeline: Pipeline;
}>();

const refreshedAt = ref(Date.now());

usePoll(60_000, {
    only: ['services', 'pipeline'],
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

const registering = ref(false);

// Filters and sort live in the query string, so a filtered view can be shared and survives back/forward.
type SortKey = 'status' | 'name' | 'advisories' | 'last_report';
const params = new URLSearchParams(window.location.search);
const statusParam = params.get('status') as Status | null;
const statusFilter = ref<Status | null>(
    statusParam && statusOrder.includes(statusParam) ? statusParam : null,
);
const query = ref(params.get('q') ?? '');
const environment = ref(params.get('env') ?? '');
const sortKey = ref<SortKey>((params.get('sort') as SortKey) || 'status');
const sortDescending = ref(params.get('dir') === 'desc');

watch([statusFilter, query, environment, sortKey, sortDescending], () => {
    const next = new URLSearchParams();
    const entries: [string, string | null | false][] = [
        ['status', statusFilter.value],
        ['q', query.value.trim()],
        ['env', environment.value],
        ['sort', sortKey.value !== 'status' && sortKey.value],
        ['dir', sortDescending.value && 'desc'],
    ];

    for (const [key, value] of entries) {
        if (value) {
            next.set(key, value);
        }
    }

    const search = next.toString();
    // Keep Inertia's history state; only the URL changes.
    window.history.replaceState(
        window.history.state,
        '',
        `${window.location.pathname}${search ? `?${search}` : ''}`,
    );
});

const environments = computed(() =>
    [...new Set(props.services.map((service) => service.environment))].sort(
        (a, b) =>
            Number(b.startsWith('prod')) - Number(a.startsWith('prod')) ||
            a.localeCompare(b),
    ),
);

function toggleFilter(status: Status): void {
    statusFilter.value = statusFilter.value === status ? null : status;
}

function clearFilters(): void {
    statusFilter.value = null;
    query.value = '';
    environment.value = '';
}

function sortBy(key: SortKey): void {
    if (sortKey.value === key) {
        sortDescending.value = !sortDescending.value;
    } else {
        sortKey.value = key;
        sortDescending.value = key === 'advisories' || key === 'last_report';
    }
}

function ariaSort(key: SortKey): 'ascending' | 'descending' | 'none' {
    if (sortKey.value !== key) {
        return 'none';
    }

    return sortDescending.value ? 'descending' : 'ascending';
}

function openAdvisories(service: MonitoredService) {
    return service.dependencies.flatMap((dependency) =>
        dependency.advisories.filter((advisory) => !advisory.accepted),
    );
}

function advisoryCount(service: MonitoredService): number {
    return openAdvisories(service).length;
}

function exploitedCount(service: MonitoredService): number {
    return openAdvisories(service).filter(
        (advisory) => advisory.known_exploited,
    ).length;
}

function runtimes(service: MonitoredService): string {
    return service.dependencies
        .filter((dependency) => dependency.ecosystem === 'runtime')
        .map((dependency) => `${dependency.name} ${dependency.version}`)
        .join(' · ');
}

const sorters: Record<
    SortKey,
    (a: MonitoredService, b: MonitoredService) => number
> = {
    // The server already sends worst first, malware first.
    status: () => 0,
    name: (a, b) =>
        a.name.localeCompare(b.name) ||
        a.environment.localeCompare(b.environment),
    advisories: (a, b) =>
        advisoryCount(a) - advisoryCount(b) ||
        exploitedCount(a) - exploitedCount(b),
    last_report: (a, b) =>
        new Date(a.last_ping_at ?? 0).getTime() -
        new Date(b.last_ping_at ?? 0).getTime(),
};

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();
    const filtered = props.services.filter(
        (service) =>
            (statusFilter.value === null ||
                service.status === statusFilter.value) &&
            (environment.value === '' ||
                service.environment === environment.value) &&
            (needle === '' ||
                service.name.toLowerCase().includes(needle) ||
                service.environment.toLowerCase().includes(needle)),
    );
    const direction = sortDescending.value ? -1 : 1;

    return filtered.sort((a, b) => direction * sorters[sortKey.value](a, b));
});

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
            <div class="flex flex-wrap items-center gap-4">
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
                <Button @click="registering = true">
                    <Plus aria-hidden="true" />
                    Register service
                </Button>
            </div>
        </header>

        <div
            v-if="pipeline.stalled || pipeline.failed_jobs > 0"
            role="alert"
            class="flex gap-2.5 rounded-xl bg-status-warning-soft p-4 text-sm text-status-warning-foreground dark:text-status-warning"
        >
            <TriangleAlert class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <div>
                <p v-if="pipeline.stalled" class="font-medium">
                    Grading has stalled: the last evaluation finished
                    {{
                        pipeline.last_evaluated_at
                            ? lastSeen(pipeline.last_evaluated_at).toLowerCase()
                            : 'never'
                    }}. The statuses below may be out of date. Check that the
                    queue worker and the scheduler are running.
                </p>
                <p v-if="pipeline.failed_jobs > 0">
                    {{ plural(pipeline.failed_jobs, 'job has', 'jobs have') }}
                    failed. Inspect them with
                    <code class="font-mono">php artisan queue:failed</code>.
                </p>
            </div>
        </div>

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
                Register a service to get its API token, then have it post its
                dependency manifest to
                <code class="font-mono">/api/v1/telemetry/heartbeat</code>.
            </p>
            <Button @click="registering = true">
                <Plus aria-hidden="true" />
                Register service
            </Button>
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
                <label for="environment-filter" class="sr-only"
                    >Environment</label
                >
                <select
                    v-if="environments.length > 1"
                    id="environment-filter"
                    v-model="environment"
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-xs focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                >
                    <option value="">All environments</option>
                    <option v-for="env in environments" :key="env" :value="env">
                        {{ env }}
                    </option>
                </select>
                <Badge
                    v-if="statusFilter"
                    variant="outline"
                    class="h-7 gap-1.5 px-2.5"
                >
                    Status: {{ statusMeta[statusFilter].label }}
                </Badge>
                <Button
                    v-if="statusFilter || query || environment"
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
                            <TableHead
                                v-for="column in [
                                    {
                                        key: 'name',
                                        label: 'Service',
                                        class: 'pl-4',
                                    },
                                    {
                                        key: 'status',
                                        label: 'Status',
                                        class: '',
                                    },
                                    {
                                        key: null,
                                        label: 'Dependencies',
                                        class: 'hidden md:table-cell',
                                    },
                                    {
                                        key: 'advisories',
                                        label: 'Open advisories',
                                        class: 'hidden text-right sm:table-cell',
                                    },
                                    {
                                        key: 'last_report',
                                        label: 'Last report',
                                        class: 'hidden pr-4 text-right lg:table-cell',
                                    },
                                ] as const"
                                :key="column.label"
                                :class="column.class"
                                :aria-sort="
                                    column.key
                                        ? ariaSort(column.key)
                                        : undefined
                                "
                            >
                                <button
                                    v-if="column.key"
                                    type="button"
                                    class="-mx-1 inline-flex cursor-pointer items-center gap-1 rounded-sm px-1 hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    @click="sortBy(column.key)"
                                >
                                    {{ column.label }}
                                    <component
                                        :is="
                                            ariaSort(column.key) === 'none'
                                                ? ArrowUpDown
                                                : sortDescending
                                                  ? ArrowDown
                                                  : ArrowUp
                                        "
                                        class="size-3.5"
                                        :class="{
                                            'opacity-40':
                                                ariaSort(column.key) === 'none',
                                        }"
                                        aria-hidden="true"
                                    />
                                </button>
                                <template v-else>{{ column.label }}</template>
                            </TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        <TableRow
                            v-for="service in visible"
                            :key="service.id"
                            class="cursor-pointer"
                            @click="
                                router.visit(ServiceController.show(service.id))
                            "
                        >
                            <TableCell class="py-3 pl-4">
                                <!-- The link gives keyboard and screen-reader users the same entry point as a row click. -->
                                <Link
                                    :href="ServiceController.show(service.id)"
                                    class="rounded-sm font-medium focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                                    @click.stop
                                >
                                    {{ service.name }}
                                </Link>
                                <div
                                    class="mt-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ service.environment
                                    }}<template v-if="runtimes(service)">
                                        · {{ runtimes(service) }}</template
                                    >
                                </div>
                            </TableCell>
                            <TableCell>
                                <div class="flex flex-col items-start gap-1">
                                    <span
                                        class="flex flex-wrap items-center gap-1.5"
                                    >
                                        <StatusBadge :status="service.status" />
                                        <Badge
                                            v-if="service.malicious"
                                            class="gap-1 border-transparent bg-status-critical text-status-critical-foreground"
                                        >
                                            <Skull aria-hidden="true" />
                                            Malware
                                        </Badge>
                                        <Badge
                                            v-if="service.overdue"
                                            variant="outline"
                                            class="gap-1 border-status-critical text-status-critical"
                                        >
                                            <CalendarClock aria-hidden="true" />
                                            {{ service.overdue }} overdue
                                        </Badge>
                                    </span>
                                    <span
                                        v-if="service.status_changed_at"
                                        class="text-xs text-muted-foreground"
                                        >since
                                        {{
                                            lastSeen(
                                                service.status_changed_at,
                                            ).toLowerCase()
                                        }}</span
                                    >
                                </div>
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
                                <span
                                    v-if="exploitedCount(service) > 0"
                                    class="block text-xs font-medium text-status-critical"
                                    >{{ exploitedCount(service) }} exploited in
                                    the wild</span
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

        <RegisterServiceSheet v-model:open="registering" />
    </div>
</template>
