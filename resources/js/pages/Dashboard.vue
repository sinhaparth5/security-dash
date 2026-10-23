<script setup lang="ts">
import { Head, Link, router, usePoll } from '@inertiajs/vue3';
import { useNow } from '@vueuse/core';
import {
    Activity,
    ArrowDown,
    ArrowUp,
    ArrowUpDown,
    Boxes,
    CalendarClock,
    CalendarDays,
    CalendarRange,
    Clock,
    Hourglass,
    ListChecks,
    NotebookPen,
    PieChart,
    Plus,
    Search,
    ServerOff,
    Skull,
    Square,
    TriangleAlert,
    X,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import StatusCalendar from '@/components/charts/StatusCalendar.vue';
import StatusDonut from '@/components/charts/StatusDonut.vue';
import StatusTrend from '@/components/charts/StatusTrend.vue';
import Panel from '@/components/Panel.vue';
import FleetRadar from '@/components/FleetRadar.vue';
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
import type {
    Advisory,
    Dependency,
    MonitoredService,
    Pipeline,
    Status,
    StatusHistory,
} from '@/types';

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
    history: StatusHistory;
}>();

const refreshedAt = ref(Date.now());

usePoll(60_000, {
    only: ['services', 'pipeline', 'history'],
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

type Finding = {
    key: string;
    service: MonitoredService;
    dependency: Dependency;
    advisory: Advisory;
    due: Date;
};

// Every open (not risk-accepted) advisory in the fleet, worst first: malware, exploited, overdue, then EPSS.
const findings = computed<Finding[]>(() =>
    props.services
        .flatMap((service) =>
            service.dependencies.flatMap((dependency) =>
                dependency.advisories
                    .filter((advisory) => !advisory.accepted)
                    .map((advisory) => ({
                        key: `${service.id}-${dependency.id}-${advisory.id}`,
                        service,
                        dependency,
                        advisory,
                        due: new Date(advisory.due_at),
                    })),
            ),
        )
        .sort(
            (a, b) =>
                Number(b.advisory.malicious) - Number(a.advisory.malicious) ||
                Number(b.advisory.known_exploited) -
                    Number(a.advisory.known_exploited) ||
                Number(b.advisory.overdue) - Number(a.advisory.overdue) ||
                (b.advisory.epss ?? 0) - (a.advisory.epss ?? 0),
        ),
);

const overdue = computed(() =>
    findings.value.filter((finding) => finding.advisory.overdue),
);

const nextDeadline = computed(() =>
    findings.value
        .filter((finding) => finding.due.getTime() > now.value.getTime())
        .sort((a, b) => a.due.getTime() - b.due.getTime())
        .at(0),
);

function countdown(due: Date): string {
    const minutes = Math.max(
        0,
        Math.floor((due.getTime() - now.value.getTime()) / 60_000),
    );
    const days = Math.floor(minutes / 1440);
    const hours = Math.floor((minutes % 1440) / 60);

    return days > 0 ? `${days}d ${hours}h` : `${hours}h ${minutes % 60}m`;
}

// This week, Monday first, with the fix-by dates that land on each day.
const week = computed(() => {
    const monday = new Date(now.value);
    monday.setHours(0, 0, 0, 0);
    monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));

    return Array.from({ length: 7 }, (_, index) => {
        const day = new Date(monday);
        day.setDate(monday.getDate() + index);

        return {
            day,
            label: day.toLocaleDateString(undefined, { weekday: 'long' }),
            isToday: day.toDateString() === now.value.toDateString(),
            findings: findings.value.filter(
                (finding) => finding.due.toDateString() === day.toDateString(),
            ),
        };
    });
});

// Dependencies per ecosystem, split by status, for the stacked bars.
const ecosystems = computed(() => {
    const groups = new Map<string, Record<Status, number>>();

    for (const service of props.services) {
        for (const dependency of service.dependencies) {
            const group = groups.get(dependency.ecosystem) ?? {
                red: 0,
                yellow: 0,
                unknown: 0,
                green: 0,
            };
            group[dependency.status]++;
            groups.set(dependency.ecosystem, group);
        }
    }

    return [...groups.entries()]
        .map(([name, counts]) => ({
            name,
            counts,
            total: statusOrder.reduce((sum, status) => sum + counts[status], 0),
        }))
        .sort((a, b) => b.total - a.total);
});

// One plain-language line, like a daily note.
const brief = computed(() => {
    const needPatch = counts.value.red;
    const exploited = findings.value.filter(
        (finding) => finding.advisory.known_exploited,
    ).length;
    const parts = [
        needPatch
            ? `${plural(needPatch, 'service needs', 'services need')} a patch out of ${props.services.length}.`
            : `All ${plural(props.services.length, 'service is', 'services are')} free of known advisories.`,
    ];

    if (exploited) {
        parts.push(
            `${plural(exploited, 'advisory is', 'advisories are')} exploited in the wild, so start there.`,
        );
    }

    if (overdue.value.length) {
        parts.push(
            `${plural(overdue.value.length, 'fix is', 'fixes are')} past the fix-by date.`,
        );
    } else if (nextDeadline.value) {
        parts.push(
            `The next fix-by date is ${nextDeadline.value.due.toLocaleDateString(undefined, { weekday: 'long', month: 'short', day: 'numeric' })} for ${nextDeadline.value.dependency.name} in ${nextDeadline.value.service.name}.`,
        );
    }

    if (counts.value.yellow) {
        parts.push(
            `${plural(counts.value.yellow, 'service has', 'services have')} updates waiting.`,
        );
    }

    return parts.join(' ');
});

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
        <header
            class="grid items-center gap-4 xl:grid-cols-[minmax(0,1fr)_30rem]"
        >
            <div class="flex flex-col items-center gap-3 text-center">
                <div>
                    <h1 class="text-[28px] font-bold tracking-tight lowercase">
                        service health
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        Runtime and dependency versions your services report,
                        checked against OSV and upstream releases.
                    </p>
                </div>
                <div class="flex flex-wrap items-center justify-center gap-4">
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
                        live · refreshed {{ refreshedLabel.toLowerCase() }}
                    </p>
                    <Button
                        size="sm"
                        class="bg-brand text-brand-foreground hover:bg-brand/90"
                        @click="registering = true"
                    >
                        <Plus aria-hidden="true" />
                        Register service
                    </Button>
                </div>
            </div>
            <FleetRadar
                v-if="services.length > 0"
                :services="services"
                class="h-60 w-full overflow-hidden rounded-lg border bg-card shadow-card"
            />
        </header>

        <div
            v-if="pipeline.stalled || pipeline.failed_jobs > 0"
            role="alert"
            class="flex gap-2.5 rounded-md bg-status-warning-soft p-4 text-sm text-status-warning-foreground dark:text-status-warning"
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

        <div
            v-if="services.length"
            class="grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)] xl:grid-cols-[15rem_minmax(0,1fr)_17rem]"
        >
            <div class="flex flex-col gap-4">
                <Panel title="fleet" :icon="PieChart">
                    <StatusDonut v-model="statusFilter" :counts="counts" />
                </Panel>

                <Panel title="ecosystems" :icon="Boxes">
                    <ul class="flex flex-col gap-3">
                        <li
                            v-for="ecosystem in ecosystems"
                            :key="ecosystem.name"
                            class="flex flex-col gap-1"
                        >
                            <span
                                class="flex justify-between text-xs text-muted-foreground"
                            >
                                <span class="text-foreground">{{
                                    ecosystem.name
                                }}</span>
                                <span class="tabular-nums">{{
                                    ecosystem.total
                                }}</span>
                            </span>
                            <span
                                class="flex h-2 gap-[2px] overflow-hidden rounded-full bg-muted"
                                role="img"
                                :aria-label="`${ecosystem.name}: ${statusOrder
                                    .filter(
                                        (status) => ecosystem.counts[status],
                                    )
                                    .map(
                                        (status) =>
                                            `${ecosystem.counts[status]} ${statusMeta[status].label.toLowerCase()}`,
                                    )
                                    .join(', ')}`"
                            >
                                <span
                                    v-for="status in statusOrder"
                                    v-show="ecosystem.counts[status]"
                                    :key="status"
                                    :class="statusMeta[status].fill"
                                    :style="{
                                        flexGrow: ecosystem.counts[status],
                                    }"
                                    :title="`${ecosystem.counts[status]} ${statusMeta[status].label.toLowerCase()}`"
                                />
                            </span>
                        </li>
                    </ul>
                </Panel>
            </div>

            <div class="flex min-w-0 flex-col gap-4">
                <Panel title="status changes" :icon="CalendarDays">
                    <StatusCalendar :events="history.events" />
                </Panel>

                <Panel title="daily brief" :icon="NotebookPen">
                    <p class="text-sm leading-relaxed">{{ brief }}</p>
                </Panel>

                <Panel title="fleet over 30 days" :icon="Activity">
                    <StatusTrend :trend="history.trend" />
                </Panel>
            </div>

            <div
                class="flex flex-col gap-4 lg:col-span-2 lg:grid lg:grid-cols-2 xl:col-span-1 xl:flex"
            >
                <Panel title="patch queue" :icon="ListChecks">
                    <p
                        v-if="findings.length === 0"
                        class="text-xs text-muted-foreground"
                    >
                        Nothing to patch. Every advisory is fixed or accepted.
                    </p>
                    <ul v-else class="flex flex-col">
                        <li
                            v-for="finding in findings.slice(0, 8)"
                            :key="finding.key"
                        >
                            <Link
                                :href="
                                    ServiceController.show(finding.service.id)
                                "
                                class="flex items-start gap-2 rounded-sm px-1 py-1.5 text-xs transition-colors hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <Square
                                    class="mt-0.5 size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate">
                                        {{ finding.dependency.name }}
                                        <span class="text-muted-foreground"
                                            >in {{ finding.service.name }}</span
                                        >
                                    </span>
                                    <span
                                        class="block truncate text-muted-foreground"
                                        >{{ finding.advisory.osv_id }}</span
                                    >
                                </span>
                                <Skull
                                    v-if="finding.advisory.malicious"
                                    class="size-3.5 shrink-0 text-status-critical"
                                    aria-label="Malware"
                                />
                                <span
                                    v-else-if="finding.advisory.known_exploited"
                                    class="shrink-0 rounded-sm bg-status-critical-soft px-1 text-status-critical"
                                    >exploited</span
                                >
                                <span
                                    v-else-if="finding.advisory.overdue"
                                    class="shrink-0 rounded-sm border border-status-critical px-1 text-status-critical"
                                    >overdue</span
                                >
                            </Link>
                        </li>
                    </ul>
                    <p
                        v-if="findings.length > 8"
                        class="text-xs text-muted-foreground"
                    >
                        +{{ findings.length - 8 }} more in the table below
                    </p>
                </Panel>

                <Panel title="countdown" :icon="Hourglass">
                    <div
                        class="flex flex-col items-center gap-1 py-2 text-center"
                    >
                        <template v-if="overdue.length">
                            <span
                                class="text-2xl font-bold text-status-critical tabular-nums"
                                >{{ overdue.length }}</span
                            >
                            <span class="text-xs text-muted-foreground"
                                >{{
                                    overdue.length === 1
                                        ? 'fix is'
                                        : 'fixes are'
                                }}
                                past the fix-by date</span
                            >
                        </template>
                        <template v-else-if="nextDeadline">
                            <span class="text-2xl font-bold tabular-nums">{{
                                countdown(nextDeadline.due)
                            }}</span>
                            <span class="text-xs text-muted-foreground"
                                >until {{ nextDeadline.dependency.name }} in
                                {{ nextDeadline.service.name }} is due</span
                            >
                        </template>
                        <template v-else>
                            <span class="text-2xl font-bold">all clear</span>
                            <span class="text-xs text-muted-foreground"
                                >no fix-by dates ahead</span
                            >
                        </template>
                    </div>
                </Panel>
            </div>
        </div>

        <Panel
            v-if="services.length"
            title="fix-by this week"
            :icon="CalendarRange"
        >
            <ol class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <li
                    v-for="day in week"
                    :key="day.label"
                    class="flex min-h-28 flex-col gap-2 rounded-md border bg-muted p-3"
                    :class="{
                        'border-brand': day.isToday,
                        'max-sm:hidden': !day.findings.length && !day.isToday,
                    }"
                >
                    <p
                        class="flex items-center gap-1.5 text-xs font-semibold lowercase"
                    >
                        <CalendarClock
                            class="size-3.5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        {{ day.label }}
                        <span v-if="day.isToday" class="font-normal text-brand"
                            >· today</span
                        >
                    </p>
                    <ul
                        v-if="day.findings.length"
                        class="flex flex-col divide-y text-xs"
                    >
                        <li v-for="finding in day.findings" :key="finding.key">
                            <Link
                                :href="
                                    ServiceController.show(finding.service.id)
                                "
                                class="flex items-center gap-2 py-1.5 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <Square
                                    class="size-3.5 shrink-0 text-muted-foreground"
                                    aria-hidden="true"
                                />
                                <span class="truncate"
                                    >{{ finding.dependency.name }} ·
                                    {{ finding.service.name }}</span
                                >
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="text-xs text-muted-foreground">
                        nothing due
                    </p>
                </li>
                <li
                    class="flex min-h-28 flex-col items-center justify-center gap-1 rounded-md border bg-card p-3 text-center"
                >
                    <span class="text-xs font-semibold lowercase"
                        >this week</span
                    >
                    <span class="text-2xl font-bold tabular-nums">{{
                        week.reduce((sum, day) => sum + day.findings.length, 0)
                    }}</span>
                    <span class="text-xs text-muted-foreground">fixes due</span>
                </li>
            </ol>
        </Panel>

        <section
            v-if="services.length === 0"
            class="flex flex-1 flex-col items-center justify-center gap-3 rounded-lg border border-dashed p-10 text-center"
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
                    class="h-9 rounded-md border border-input bg-transparent px-3 text-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
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

            <div class="overflow-hidden rounded-lg border bg-card shadow-card">
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
