<script setup lang="ts">
import { Head, Link, usePoll } from '@inertiajs/vue3';
import { ArrowLeft, Clock, ExternalLink, KeyRound, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import TokenController from '@/actions/App/Http/Controllers/TokenController';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import type { MonitoredService } from '@/types';

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
    service: MonitoredService;
}>();

usePoll(60_000, { only: ['service'] });

const groups = computed(() =>
    statusOrder.map((status) => ({
        status,
        dependencies: props.service.dependencies.filter(
            (dependency) => dependency.status === status,
        ),
    })),
);

const severityClass: Record<string, string> = {
    CRITICAL: 'bg-status-critical text-status-critical-foreground',
    HIGH: 'bg-status-critical/15 text-status-critical dark:bg-status-critical/35 dark:text-foreground',
    MODERATE:
        'bg-status-warning/25 text-status-warning-foreground dark:text-status-warning',
    MEDIUM: 'bg-status-warning/25 text-status-warning-foreground dark:text-status-warning',
    LOW: 'bg-muted text-muted-foreground',
};

function advisoryUrl(osvId: string): string | null {
    return osvId.startsWith('NODE-')
        ? null
        : `https://osv.dev/vulnerability/${encodeURIComponent(osvId)}`;
}
</script>

<template>
    <Head :title="service.name" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-col gap-3">
            <Link
                :href="dashboard()"
                class="inline-flex w-fit items-center gap-1.5 rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                All services
            </Link>
            <div class="flex flex-wrap items-center gap-3">
                <h1 class="text-2xl font-semibold tracking-tight break-all">
                    {{ service.name }}
                </h1>
                <Badge variant="outline" class="font-mono">{{
                    service.environment
                }}</Badge>
                <StatusBadge :status="service.status" />
                <Button variant="outline" size="sm" class="ml-auto" as-child>
                    <Link
                        :href="
                            TokenController.index({
                                query: { service: service.id },
                            })
                        "
                    >
                        <KeyRound aria-hidden="true" />
                        Connection and tokens
                    </Link>
                </Button>
            </div>
            <p
                class="flex flex-wrap items-center gap-1.5 text-sm text-muted-foreground"
            >
                <span
                    v-if="service.is_stale"
                    class="inline-flex items-center gap-1 font-medium text-status-warning-foreground dark:text-status-warning"
                >
                    <Clock class="size-3.5" aria-hidden="true" />
                    Stale
                </span>
                Last report
                {{ lastSeen(service.last_ping_at).toLowerCase() }} ·
                {{
                    plural(
                        service.dependencies.length,
                        'dependency',
                        'dependencies',
                    )
                }}
            </p>
        </header>

        <nav
            aria-label="Dependency summary"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <a
                v-for="group in groups"
                :key="group.status"
                :href="`#${group.status}`"
                class="flex flex-col rounded-xl border bg-card p-4 shadow-xs transition-[border-color] duration-200 hover:border-ring/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                :class="{ 'opacity-60': group.dependencies.length === 0 }"
            >
                <div class="flex items-center justify-between gap-2">
                    <span class="text-sm font-medium">{{
                        statusMeta[group.status].label
                    }}</span>
                    <span
                        class="flex size-8 items-center justify-center rounded-lg"
                        :class="[
                            statusMeta[group.status].soft,
                            statusMeta[group.status].text,
                        ]"
                    >
                        <component
                            :is="statusMeta[group.status].icon"
                            class="size-4"
                            aria-hidden="true"
                        />
                    </span>
                </div>
                <div class="mt-3 text-3xl font-semibold tabular-nums">
                    {{ group.dependencies.length }}
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                    {{ statusMeta[group.status].description }}
                </p>
            </a>
        </nav>

        <p
            v-if="service.dependencies.length === 0"
            class="rounded-xl border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            This service has not reported any dependencies yet.
        </p>

        <template v-for="group in groups" :key="group.status">
            <section
                v-if="group.dependencies.length > 0"
                :id="group.status"
                :aria-labelledby="`${group.status}-heading`"
                class="flex scroll-mt-20 flex-col gap-3"
            >
                <h2
                    :id="`${group.status}-heading`"
                    class="flex items-center gap-2 text-lg font-semibold"
                >
                    <component
                        :is="statusMeta[group.status].icon"
                        class="size-5"
                        :class="statusMeta[group.status].text"
                        aria-hidden="true"
                    />
                    {{ statusMeta[group.status].label }}
                    <span
                        class="font-normal text-muted-foreground tabular-nums"
                        >{{ group.dependencies.length }}</span
                    >
                </h2>

                <!-- Vulnerable dependencies get full cards so every advisory and its fix is visible. -->
                <ul
                    v-if="group.status === 'red'"
                    class="grid gap-3 xl:grid-cols-2"
                >
                    <li
                        v-for="dependency in group.dependencies"
                        :key="dependency.id"
                        class="rounded-xl border bg-card p-4 shadow-xs"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                        >
                            <span class="font-medium break-all">{{
                                dependency.name
                            }}</span>
                            <span
                                class="font-mono text-xs text-muted-foreground"
                                >{{ dependency.ecosystem }}</span
                            >
                        </div>
                        <div
                            class="mt-1 flex flex-wrap items-center gap-x-1.5 font-mono text-sm"
                        >
                            <span
                                class="font-medium"
                                :class="statusMeta.red.text"
                                >{{ dependency.version }}</span
                            >
                            <template
                                v-if="
                                    dependency.latest_version &&
                                    dependency.latest_version !==
                                        dependency.version
                                "
                            >
                                <span
                                    class="text-muted-foreground"
                                    aria-label="latest release"
                                    >→</span
                                >
                                <span class="font-medium">{{
                                    dependency.latest_version
                                }}</span>
                            </template>
                        </div>

                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="advisory in dependency.advisories"
                                :key="advisory.id"
                                class="rounded-md p-3 text-sm"
                                :class="statusMeta.red.soft"
                            >
                                <div class="flex flex-wrap items-center gap-2">
                                    <a
                                        v-if="advisoryUrl(advisory.osv_id)"
                                        :href="advisoryUrl(advisory.osv_id)!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 font-mono font-medium underline-offset-2 hover:underline"
                                    >
                                        {{ advisory.osv_id }}
                                        <ExternalLink
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only"
                                            >(opens osv.dev)</span
                                        >
                                    </a>
                                    <span
                                        v-else
                                        class="font-mono font-medium"
                                        >{{ advisory.osv_id }}</span
                                    >
                                    <Badge
                                        v-if="advisory.severity"
                                        variant="outline"
                                        class="border-transparent uppercase"
                                        :class="
                                            severityClass[
                                                advisory.severity.toUpperCase()
                                            ] ?? severityClass.LOW
                                        "
                                        >{{ advisory.severity }}</Badge
                                    >
                                </div>
                                <p
                                    v-if="advisory.aliases.length"
                                    class="mt-1 font-mono text-xs text-muted-foreground"
                                >
                                    {{ advisory.aliases.join(', ') }}
                                </p>
                                <p
                                    v-if="advisory.summary"
                                    class="mt-1.5 leading-relaxed"
                                >
                                    {{ advisory.summary }}
                                </p>
                                <p
                                    v-if="advisory.fixed_version"
                                    class="mt-2 inline-flex items-center gap-1.5 font-medium"
                                >
                                    <Wrench
                                        class="size-3.5"
                                        aria-hidden="true"
                                    />
                                    Upgrade to
                                    <span class="font-mono">{{
                                        advisory.fixed_version
                                    }}</span>
                                </p>
                            </li>
                        </ul>
                    </li>
                </ul>

                <div
                    v-else
                    class="overflow-hidden rounded-xl border bg-card shadow-xs"
                >
                    <Table>
                        <TableHeader>
                            <TableRow class="hover:bg-transparent">
                                <TableHead class="pl-4">Dependency</TableHead>
                                <TableHead class="hidden sm:table-cell"
                                    >Ecosystem</TableHead
                                >
                                <TableHead>Running</TableHead>
                                <TableHead class="pr-4">Latest</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            <TableRow
                                v-for="dependency in group.dependencies"
                                :key="dependency.id"
                            >
                                <TableCell
                                    class="py-3 pl-4 font-medium break-all whitespace-normal"
                                    >{{ dependency.name }}</TableCell
                                >
                                <TableCell
                                    class="hidden font-mono text-xs text-muted-foreground sm:table-cell"
                                    >{{ dependency.ecosystem }}</TableCell
                                >
                                <TableCell
                                    class="font-mono text-sm font-medium"
                                    :class="statusMeta[group.status].text"
                                    >{{ dependency.version }}</TableCell
                                >
                                <TableCell
                                    class="pr-4 font-mono text-sm"
                                    :class="
                                        group.status === 'yellow'
                                            ? 'font-medium'
                                            : 'text-muted-foreground'
                                    "
                                    >{{
                                        dependency.latest_version ?? '—'
                                    }}</TableCell
                                >
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </template>
    </div>
</template>
