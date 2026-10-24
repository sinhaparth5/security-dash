<script setup lang="ts">
import { Form, Head, Link, router, usePoll } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    ArrowLeft,
    CalendarClock,
    Check,
    Clock,
    Copy,
    ExternalLink,
    GitMerge,
    GitPullRequest,
    GitPullRequestClosed,
    History,
    KeyRound,
    Link2,
    ShieldCheck,
    Skull,
    Sparkles,
    TriangleAlert,
    Wrench,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PullRequestController from '@/actions/App/Http/Controllers/PullRequestController';
import RiskAcceptanceController from '@/actions/App/Http/Controllers/RiskAcceptanceController';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import ManifestGrid from '@/components/ascii/ManifestGrid.vue';
import TokenController from '@/actions/App/Http/Controllers/TokenController';
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { lastSeen, packageUrl, plural, versionsUrl } from '@/lib/format';
import { statusMeta, statusOrder } from '@/lib/status';
import { dashboard } from '@/routes';
import type {
    Advisory,
    AdvisoryTriage,
    Dependency,
    MonitoredService,
    RiskAcceptance,
    StatusChange,
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
    service: MonitoredService;
    history: StatusChange[];
    canOpenPullRequests: boolean;
    autoPullRequests: boolean;
    badgeUrl: string | null;
}>();

usePoll(60_000, { only: ['service', 'history'] });

/** A Yellow dependency with an open advisory has no fix released yet, so it gets a card too. */
function hasCard(dependency: Dependency): boolean {
    return (
        dependency.status === 'red' ||
        (dependency.status === 'yellow' &&
            dependency.advisories.some((advisory) => !advisory.accepted))
    );
}

const groups = computed(() =>
    statusOrder.map((status) => {
        const dependencies = props.service.dependencies.filter(
            (dependency) => dependency.status === status,
        );

        return {
            status,
            dependencies,
            cards: dependencies.filter(hasCard),
            rows: dependencies.filter((dependency) => !hasCard(dependency)),
        };
    }),
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
    return osvId.startsWith('NODE-') || osvId.startsWith('EOL-')
        ? null
        : `https://osv.dev/vulnerability/${encodeURIComponent(osvId)}`;
}

function epssLabel(epss: number): string {
    return `${(epss * 100).toFixed(epss < 0.1 ? 1 : 0)}%`;
}

const DAY = 86_400_000;

/** Fix-by countdown for an advisory still counted against the service. */
function dueLabel(advisory: Advisory): string {
    const left = new Date(advisory.due_at).getTime() - Date.now();

    if (advisory.overdue) {
        return `Overdue by ${plural(Math.max(1, Math.floor(-left / DAY)), 'day', 'days')}`;
    }

    const days = Math.ceil(left / DAY);

    return days <= 1 ? 'Fix today' : `Fix within ${days} days`;
}

const pullRequestMeta = {
    open: { label: 'Upgrade PR open', icon: GitPullRequest },
    merged: { label: 'PR merged, waiting for deploy', icon: GitMerge },
    closed: { label: 'PR closed without merging', icon: GitPullRequestClosed },
};

function endOfLife(
    dependency: Dependency,
): { label: string; past: boolean } | null {
    if (!dependency.eol_at) {
        return null;
    }

    const days = Math.ceil(
        (new Date(dependency.eol_at).getTime() - Date.now()) / DAY,
    );

    if (days <= 0) {
        return { label: `End of life since ${dependency.eol_at}`, past: true };
    }

    return days <= 180
        ? { label: `End of life in ${days} days`, past: false }
        : null;
}

/**
 * The highest version that clears every advisory still counted against the dependency.
 */
function fixVersion(dependency: Dependency): string | null {
    return (
        dependency.advisories
            .filter((advisory) => !advisory.accepted)
            .map((advisory) => advisory.fixed_version)
            .filter((version): version is string => version !== null)
            .sort((a, b) =>
                b.localeCompare(a, undefined, { numeric: true }),
            )[0] ?? null
    );
}

const fixCommands = computed(() => {
    const red = props.service.dependencies.filter(
        (dependency) => dependency.status === 'red',
    );
    const pinned = (ecosystem: Dependency['ecosystem']) =>
        red
            .filter((dependency) => dependency.ecosystem === ecosystem)
            .map((dependency) => ({
                dependency,
                version: fixVersion(dependency),
            }))
            .filter((fix) => fix.version !== null);

    const npm = pinned('npm').map(
        ({ dependency, version }) => `${dependency.name}@${version}`,
    );
    const go = pinned('go').map(
        ({ dependency, version }) =>
            `${dependency.name}@${version!.startsWith('v') ? version : `v${version}`}`,
    );

    return [
        ...(npm.length ? [`npm install ${npm.join(' ')}`] : []),
        ...(go.length ? [`go get ${go.join(' ')} && go mod tidy`] : []),
    ];
});

const urgencyLabels = ['Routine', 'Soon', 'Urgent', 'Emergency'];

const actionLabels: Record<AdvisoryTriage['action'], string> = {
    upgrade: 'upgrade',
    investigate: 'check if affected',
    accept: 'likely safe to accept',
    replace: 'remove or replace',
};

/** Below this, Jev spread its answer across options, so the label says so. */
const CONFIDENT = 0.5;

function triageLabel(triage: AdvisoryTriage): string {
    const urgency = urgencyLabels[Math.round(triage.urgency)];
    const unsure =
        Math.min(triage.urgency_confidence, triage.action_confidence) <
        CONFIDENT
            ? ' (unsure)'
            : '';

    return `${urgency}, ${actionLabels[triage.action]}${unsure}`;
}

function canBump(dependency: Dependency): boolean {
    return (
        props.canOpenPullRequests &&
        props.service.repository !== null &&
        ['npm', 'go'].includes(dependency.ecosystem) &&
        fixVersion(dependency) !== null
    );
}

const openingPullRequest = ref<number | null>(null);

function openPullRequest(dependency: Dependency): void {
    router.post(
        PullRequestController.store.url({
            service: props.service.id,
            dependency: dependency.id,
        }),
        {},
        {
            preserveScroll: true,
            only: ['service'],
            onStart: () => (openingPullRequest.value = dependency.id),
            onFinish: () => (openingPullRequest.value = null),
        },
    );
}

const { copy, copied, text: copiedText } = useClipboard({ legacy: true });

const badgeMarkdown = computed(() =>
    props.badgeUrl ? `![Patchwatch status](${props.badgeUrl})` : null,
);

function rotateBadge(): void {
    router.post(
        ServiceController.rotateBadge.url(props.service.id),
        {},
        {
            preserveScroll: true,
            only: ['badgeUrl'],
            onBefore: () =>
                props.badgeUrl === null ||
                confirm(
                    'Replace the badge URL? READMEs using the old one will show a broken image.',
                ),
        },
    );
}

// One inline "accept risk" form open at a time, keyed by dependency and advisory.
const accepting = ref<string | null>(null);

function acceptKey(dependency: Dependency, advisory: Advisory): string {
    return `${dependency.id}:${advisory.osv_id}`;
}

const tomorrow = new Date(Date.now() + DAY).toISOString().slice(0, 10);

function revokeAcceptance(acceptance: RiskAcceptance): void {
    router.delete(
        RiskAcceptanceController.destroy.url({
            service: props.service.id,
            riskAcceptance: acceptance.id,
        }),
        {
            preserveScroll: true,
            only: ['service'],
            onBefore: () =>
                confirm(
                    `Count ${acceptance.osv_id} against ${props.service.name} again?`,
                ),
        },
    );
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
                <h1 class="text-[28px] font-bold tracking-tight break-all">
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
                <template v-if="service.status_changed_at">
                    {{ statusMeta[service.status].label }} since
                    {{ lastSeen(service.status_changed_at).toLowerCase() }}
                    ·
                </template>
                Graded
                {{
                    service.evaluated_at
                        ? lastSeen(service.evaluated_at).toLowerCase()
                        : 'not yet'
                }}
                ·
                {{
                    plural(
                        service.dependencies.length,
                        'dependency',
                        'dependencies',
                    )
                }}
            </p>
        </header>

        <ManifestGrid
            v-if="service.dependencies.length > 0"
            :dependencies="service.dependencies"
        />

        <div
            v-if="service.malicious"
            role="alert"
            class="flex gap-3 rounded-lg border border-status-critical bg-status-critical-soft p-4 text-sm"
        >
            <Skull
                class="mt-0.5 size-5 shrink-0 text-status-critical"
                aria-hidden="true"
            />
            <div>
                <p class="font-semibold text-status-critical">
                    Malicious package detected
                </p>
                <p class="mt-1 leading-relaxed">
                    OSV reports a dependency below as malware, not just a bug.
                    Treat every machine that installed it as compromised: remove
                    the package, rebuild from a clean state, and rotate any
                    secrets it could read.
                </p>
            </div>
        </div>

        <section
            v-if="fixCommands.length"
            aria-labelledby="fix-heading"
            class="flex flex-col gap-2 rounded-lg border bg-card p-4 shadow-card"
        >
            <h2 id="fix-heading" class="flex items-center gap-2 font-semibold">
                <Wrench
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Fix the advisories
            </h2>
            <p class="text-sm text-muted-foreground">
                Upgrades every vulnerable package to the lowest version that
                clears all of its advisories. Run it in the service's repo.
            </p>
            <div
                v-for="command in fixCommands"
                :key="command"
                class="flex items-start gap-2"
            >
                <pre
                    class="min-w-0 flex-1 overflow-x-auto rounded-md bg-muted p-3 font-mono text-xs"
                ><code>{{ command }}</code></pre>
                <Button
                    variant="outline"
                    size="icon"
                    :aria-label="
                        copied && copiedText === command
                            ? 'Copied'
                            : 'Copy command'
                    "
                    @click="copy(command)"
                >
                    <Check
                        v-if="copied && copiedText === command"
                        aria-hidden="true"
                    />
                    <Copy v-else aria-hidden="true" />
                </Button>
            </div>
        </section>

        <nav
            aria-label="Dependency summary"
            class="grid grid-cols-2 gap-3 lg:grid-cols-4"
        >
            <a
                v-for="group in groups"
                :key="group.status"
                :href="`#${group.status}`"
                class="flex flex-col rounded-lg border bg-card p-4 shadow-card transition-[border-color] duration-200 hover:border-ring/40 focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
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
            class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
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
                    v-if="group.cards.length > 0"
                    class="grid gap-3 xl:grid-cols-2"
                >
                    <li
                        v-for="dependency in group.cards"
                        :key="dependency.id"
                        class="rounded-lg border bg-card p-4 shadow-card"
                    >
                        <div
                            class="flex flex-wrap items-baseline justify-between gap-x-3 gap-y-1"
                        >
                            <span
                                class="flex flex-wrap items-center gap-2 font-medium break-all"
                            >
                                <a
                                    v-if="packageUrl(dependency)"
                                    :href="packageUrl(dependency)!"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="underline-offset-2 hover:underline"
                                    >{{ dependency.name
                                    }}<span class="sr-only">
                                        (opens in a new tab)</span
                                    ></a
                                >
                                <template v-else>{{
                                    dependency.name
                                }}</template>
                                <Badge
                                    v-if="dependency.malicious"
                                    class="gap-1 border-transparent bg-status-critical text-status-critical-foreground"
                                >
                                    <Skull aria-hidden="true" />
                                    Malware
                                </Badge>
                                <Badge
                                    v-if="dependency.lookalike"
                                    variant="outline"
                                    class="gap-1 font-normal"
                                    :class="statusMeta.yellow.text"
                                    :title="`One letter away from the popular package ${dependency.lookalike}. Check this is the package you meant to install.`"
                                >
                                    <TriangleAlert aria-hidden="true" />
                                    Looks like {{ dependency.lookalike }}
                                </Badge>
                            </span>
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
                                :class="statusMeta[dependency.status].text"
                                >{{ dependency.version }}</span
                            >
                            <template
                                v-if="
                                    dependency.latest_version &&
                                    dependency.latest_version !==
                                        dependency.version.replace(
                                            /^(go|v)/,
                                            '',
                                        )
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
                        <a
                            v-if="dependency.pull_request_url"
                            :href="dependency.pull_request_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium underline-offset-2 hover:underline"
                        >
                            <component
                                :is="
                                    pullRequestMeta[
                                        dependency.pull_request_state ?? 'open'
                                    ].icon
                                "
                                class="size-4"
                                aria-hidden="true"
                            />
                            {{
                                pullRequestMeta[
                                    dependency.pull_request_state ?? 'open'
                                ].label
                            }}
                            <ExternalLink class="size-3" aria-hidden="true" />
                            <span class="sr-only">(opens GitHub)</span>
                        </a>
                        <Button
                            v-else-if="canBump(dependency)"
                            variant="outline"
                            size="sm"
                            class="mt-2"
                            :disabled="openingPullRequest === dependency.id"
                            @click="openPullRequest(dependency)"
                        >
                            <GitPullRequest aria-hidden="true" />
                            {{
                                openingPullRequest === dependency.id
                                    ? 'Opening PR…'
                                    : `Open PR to ${fixVersion(dependency)}`
                            }}
                        </Button>

                        <ul class="mt-3 space-y-2">
                            <li
                                v-for="advisory in dependency.advisories"
                                :key="advisory.id"
                                class="rounded-md p-3 text-sm"
                                :class="
                                    advisory.accepted
                                        ? 'border border-dashed opacity-75'
                                        : statusMeta[dependency.status].soft
                                "
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
                                    <Badge
                                        v-if="advisory.known_exploited"
                                        class="border-transparent bg-status-critical text-status-critical-foreground"
                                        title="Listed in CISA's Known Exploited Vulnerabilities catalog"
                                        >Exploited in the wild</Badge
                                    >
                                    <Badge
                                        v-if="advisory.epss !== null"
                                        variant="outline"
                                        class="font-mono"
                                        :title="`EPSS: ${epssLabel(advisory.epss)} chance of exploitation in the next 30 days`"
                                        >EPSS
                                        {{ epssLabel(advisory.epss) }}</Badge
                                    >
                                    <Badge
                                        v-if="advisory.accepted"
                                        variant="outline"
                                        class="gap-1"
                                    >
                                        <ShieldCheck aria-hidden="true" />
                                        Risk accepted
                                    </Badge>
                                    <Badge
                                        v-else
                                        variant="outline"
                                        class="gap-1"
                                        :class="
                                            advisory.overdue
                                                ? 'border-status-critical text-status-critical'
                                                : ''
                                        "
                                        :title="`Fix by ${new Date(advisory.due_at).toLocaleDateString()}, from its severity and when Patchwatch first saw it`"
                                    >
                                        <CalendarClock aria-hidden="true" />
                                        {{ dueLabel(advisory) }}
                                    </Badge>
                                    <Badge
                                        v-if="
                                            advisory.triage &&
                                            !advisory.accepted
                                        "
                                        variant="outline"
                                        class="gap-1"
                                        :title="`AI triage by Jev. Urgency confidence ${Math.round(advisory.triage.urgency_confidence * 100)}%, next step confidence ${Math.round(advisory.triage.action_confidence * 100)}%. A suggestion, not part of the grade.`"
                                    >
                                        <Sparkles aria-hidden="true" />
                                        <span class="sr-only">AI triage:</span>
                                        {{ triageLabel(advisory.triage) }}
                                    </Badge>
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
                                <p
                                    v-else-if="dependency.status === 'yellow'"
                                    class="mt-2 text-muted-foreground"
                                >
                                    No fixed version released yet
                                </p>
                                <p
                                    v-if="advisory.affected_packages.length"
                                    class="mt-1 text-muted-foreground"
                                >
                                    Only affects
                                    <code class="font-mono break-all">{{
                                        advisory.affected_packages.join(', ')
                                    }}</code>
                                </p>

                                <div
                                    v-if="
                                        !advisory.accepted &&
                                        !advisory.malicious &&
                                        accepting !==
                                            acceptKey(dependency, advisory)
                                    "
                                    class="mt-2"
                                >
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="-ml-2 h-7"
                                        @click="
                                            accepting = acceptKey(
                                                dependency,
                                                advisory,
                                            )
                                        "
                                    >
                                        <ShieldCheck aria-hidden="true" />
                                        Accept risk
                                    </Button>
                                </div>
                                <Form
                                    v-else-if="
                                        accepting ===
                                        acceptKey(dependency, advisory)
                                    "
                                    v-bind="
                                        RiskAcceptanceController.store.form(
                                            service.id,
                                        )
                                    "
                                    :options="{
                                        preserveScroll: true,
                                        only: ['service'],
                                    }"
                                    class="mt-3 flex flex-col gap-3 rounded-md border bg-card p-3"
                                    v-slot="{ errors, processing }"
                                    @success="accepting = null"
                                >
                                    <input
                                        type="hidden"
                                        name="osv_id"
                                        :value="advisory.osv_id"
                                    />
                                    <div class="grid gap-1.5">
                                        <Label :for="`reason-${advisory.id}`"
                                            >Why is this acceptable?</Label
                                        >
                                        <textarea
                                            :id="`reason-${advisory.id}`"
                                            v-focus
                                            name="reason"
                                            required
                                            maxlength="1000"
                                            rows="2"
                                            placeholder="Not affected: we never call the vulnerable function."
                                            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-card focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                                        />
                                        <InputError :message="errors.reason" />
                                    </div>
                                    <div class="grid gap-1.5">
                                        <Label :for="`expires-${advisory.id}`"
                                            >Re-check on (optional)</Label
                                        >
                                        <Input
                                            :id="`expires-${advisory.id}`"
                                            type="date"
                                            name="expires_at"
                                            :min="tomorrow"
                                            class="w-fit"
                                        />
                                        <InputError
                                            :message="errors.expires_at"
                                        />
                                    </div>
                                    <div class="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            :disabled="processing"
                                            >Accept risk</Button
                                        >
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            @click="accepting = null"
                                            >Cancel</Button
                                        >
                                    </div>
                                </Form>
                            </li>
                        </ul>
                    </li>
                </ul>

                <div
                    v-if="group.rows.length > 0"
                    class="overflow-hidden rounded-lg border bg-card shadow-card"
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
                                v-for="dependency in group.rows"
                                :key="dependency.id"
                            >
                                <TableCell
                                    class="py-3 pl-4 font-medium break-all whitespace-normal"
                                >
                                    <a
                                        v-if="packageUrl(dependency)"
                                        :href="packageUrl(dependency)!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="underline-offset-2 hover:underline"
                                        >{{ dependency.name
                                        }}<span class="sr-only">
                                            (opens in a new tab)</span
                                        ></a
                                    >
                                    <template v-else>{{
                                        dependency.name
                                    }}</template>
                                    <Badge
                                        v-if="endOfLife(dependency)"
                                        variant="outline"
                                        class="ml-2 gap-1 font-normal"
                                        :class="
                                            endOfLife(dependency)!.past
                                                ? statusMeta.red.text
                                                : statusMeta.yellow.text
                                        "
                                    >
                                        <Clock aria-hidden="true" />
                                        {{ endOfLife(dependency)!.label }}
                                    </Badge>
                                    <Badge
                                        v-if="dependency.lookalike"
                                        variant="outline"
                                        class="ml-2 gap-1 font-normal"
                                        :class="statusMeta.yellow.text"
                                        :title="`One letter away from the popular package ${dependency.lookalike}. Check this is the package you meant to install.`"
                                    >
                                        <TriangleAlert aria-hidden="true" />
                                        Looks like {{ dependency.lookalike }}
                                    </Badge>
                                </TableCell>
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
                                >
                                    <a
                                        v-if="
                                            group.status === 'yellow' &&
                                            versionsUrl(dependency)
                                        "
                                        :href="versionsUrl(dependency)!"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="inline-flex items-center gap-1 underline-offset-2 hover:underline"
                                        >{{ dependency.latest_version }}
                                        <ExternalLink
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                        <span class="sr-only"
                                            >(see what changed, opens in a new
                                            tab)</span
                                        ></a
                                    >
                                    <template v-else>{{
                                        dependency.latest_version ?? '—'
                                    }}</template>
                                </TableCell>
                            </TableRow>
                        </TableBody>
                    </Table>
                </div>
            </section>
        </template>

        <section
            v-if="service.risk_acceptances.length"
            aria-labelledby="accepted-heading"
            class="flex flex-col gap-3"
        >
            <h2
                id="accepted-heading"
                class="flex items-center gap-2 text-lg font-semibold"
            >
                <ShieldCheck
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                Accepted risks
                <span class="font-normal text-muted-foreground tabular-nums">{{
                    service.risk_acceptances.length
                }}</span>
            </h2>
            <ul class="grid gap-2 xl:grid-cols-2">
                <li
                    v-for="acceptance in service.risk_acceptances"
                    :key="acceptance.id"
                    class="flex items-start justify-between gap-3 rounded-lg border bg-card p-3 text-sm"
                >
                    <div class="min-w-0">
                        <div class="flex flex-wrap items-center gap-2">
                            <a
                                v-if="advisoryUrl(acceptance.osv_id)"
                                :href="advisoryUrl(acceptance.osv_id)!"
                                target="_blank"
                                rel="noopener noreferrer"
                                class="inline-flex items-center gap-1 font-mono font-medium underline-offset-2 hover:underline"
                            >
                                {{ acceptance.osv_id }}
                                <ExternalLink
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                <span class="sr-only">(opens osv.dev)</span>
                            </a>
                            <span v-else class="font-mono font-medium">{{
                                acceptance.osv_id
                            }}</span>
                            <Badge
                                v-if="!acceptance.active"
                                variant="outline"
                                :class="statusMeta.yellow.text"
                                >Expired, counts again</Badge
                            >
                        </div>
                        <p class="mt-1 leading-relaxed">
                            {{ acceptance.reason }}
                        </p>
                        <p class="mt-1 text-xs text-muted-foreground">
                            {{ acceptance.accepted_by ?? 'Someone' }} ·
                            {{
                                acceptance.expires_at
                                    ? `until ${acceptance.expires_at}`
                                    : 'no expiry'
                            }}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="revokeAcceptance(acceptance)"
                        >Remove</Button
                    >
                </li>
            </ul>
        </section>

        <section
            v-if="history.length"
            aria-labelledby="history-heading"
            class="flex flex-col gap-3"
        >
            <h2
                id="history-heading"
                class="flex items-center gap-2 text-lg font-semibold"
            >
                <History
                    class="size-5 text-muted-foreground"
                    aria-hidden="true"
                />
                Status history
            </h2>
            <ol class="flex flex-col gap-0 border-l pl-4">
                <li
                    v-for="change in history"
                    :key="change.id"
                    class="relative flex flex-wrap items-center gap-2 py-1.5 text-sm"
                >
                    <span
                        class="absolute top-1/2 -left-[1.3rem] size-2.5 -translate-y-1/2 rounded-full border-2 border-background"
                        :class="statusMeta[change.status].badge"
                        aria-hidden="true"
                    />
                    <StatusBadge :status="change.status" />
                    <time
                        :datetime="change.created_at"
                        :title="new Date(change.created_at).toLocaleString()"
                        class="text-muted-foreground"
                        >{{ lastSeen(change.created_at) }}</time
                    >
                </li>
            </ol>
        </section>
        <section
            aria-labelledby="integrations-heading"
            class="flex flex-col gap-4 rounded-lg border bg-card p-4 shadow-card"
        >
            <h2
                id="integrations-heading"
                class="flex items-center gap-2 font-semibold"
            >
                <Link2
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Repository and badge
            </h2>
            <Form
                v-if="canOpenPullRequests"
                v-bind="ServiceController.updateRepository.form(service.id)"
                :options="{ preserveScroll: true, only: ['service'] }"
                class="flex flex-col gap-1.5"
                v-slot="{ errors, processing }"
            >
                <Label for="repository">GitHub repository</Label>
                <p class="text-xs text-muted-foreground">
                    Link the repo to open version-bump PRs from vulnerable
                    dependencies.
                </p>
                <div class="flex flex-wrap gap-2">
                    <Input
                        id="repository"
                        name="repository"
                        :default-value="service.repository ?? ''"
                        placeholder="acme/billing-api"
                        autocomplete="off"
                        spellcheck="false"
                        class="max-w-xs font-mono"
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        :disabled="processing"
                        >Save</Button
                    >
                </div>
                <InputError :message="errors.repository" />
                <label class="mt-1 flex items-start gap-2 text-sm">
                    <input
                        type="checkbox"
                        name="auto_pull_requests"
                        value="1"
                        :checked="autoPullRequests"
                        class="mt-0.5 size-4 accent-primary"
                    />
                    <span
                        >Open upgrade PRs automatically when a dependency turns
                        red and a fix is known.</span
                    >
                </label>
            </Form>
            <div
                class="flex flex-col gap-1.5"
                :class="{ 'border-t pt-4': canOpenPullRequests }"
            >
                <h3 class="text-sm font-medium">README status badge</h3>
                <p class="text-xs text-muted-foreground">
                    A public image of this service's status. Anyone with the URL
                    can see the status, so rotate it if it leaks.
                </p>
                <div
                    v-if="badgeMarkdown"
                    class="flex flex-wrap items-center gap-2"
                >
                    <img :src="badgeUrl!" alt="" height="20" />
                    <pre
                        class="min-w-0 flex-1 overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs"
                    ><code>{{ badgeMarkdown }}</code></pre>
                    <Button
                        variant="outline"
                        size="icon"
                        :aria-label="
                            copied && copiedText === badgeMarkdown
                                ? 'Copied'
                                : 'Copy badge markdown'
                        "
                        @click="copy(badgeMarkdown)"
                    >
                        <Check
                            v-if="copied && copiedText === badgeMarkdown"
                            aria-hidden="true"
                        />
                        <Copy v-else aria-hidden="true" />
                    </Button>
                </div>
                <Button
                    variant="outline"
                    size="sm"
                    class="w-fit"
                    @click="rotateBadge"
                    >{{
                        badgeUrl ? 'Rotate badge URL' : 'Create badge'
                    }}</Button
                >
            </div>
        </section>
    </div>
</template>
