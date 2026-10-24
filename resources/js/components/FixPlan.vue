<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import {
    ArrowUpCircle,
    Check,
    Copy,
    ExternalLink,
    GitPullRequest,
    Pin,
    RefreshCw,
    Route,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import PullRequestController from '@/actions/App/Http/Controllers/PullRequestController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { plural } from '@/lib/format';
import type { FixStep } from '@/types';

/**
 * The upgrade plan worked out from the dependency graph: which change clears which advisories,
 * cheapest first, with the command (or package.json snippet) for each.
 */
const props = defineProps<{
    plan: FixStep[];
    serviceId: number;
    /** Whether a PR can be opened in the linked repository. */
    canOpenPullRequest: boolean;
    pullRequestUrl: string | null;
}>();

const opening = ref(false);

/** Bumps and overrides change package.json; a plan of lockfile refreshes only has nothing to commit. */
const changesManifest = computed(() =>
    props.plan.some((step) => step.action !== 'refresh'),
);

function openPullRequest(): void {
    router.post(
        PullRequestController.plan.url(props.serviceId),
        {},
        {
            preserveScroll: true,
            only: ['service'],
            onStart: () => (opening.value = true),
            onFinish: () => (opening.value = false),
        },
    );
}

const { copy, copied, text: copiedText } = useClipboard({ legacy: true });

const actionMeta = {
    refresh: {
        icon: RefreshCw,
        label: 'Refresh the lockfile',
        hint: 'Today’s ranges already resolve to a fixed version; package.json stays as it is.',
    },
    bump: {
        icon: ArrowUpCircle,
        label: 'Upgrade',
        hint: 'The first release whose own dependencies pull in the fix.',
    },
    override: {
        icon: Pin,
        label: 'Override',
        hint: 'No release pulls the fix in yet. Force it in package.json, then test: the parent may not expect it.',
    },
};

function command(step: FixStep): string {
    switch (step.action) {
        case 'refresh':
            return `npm update ${step.via.join(' ')}`;
        case 'bump':
            return `npm install ${step.package}@${step.to}`;
        case 'override':
            return `"overrides": { "${step.package}": "^${step.to}" }`;
    }
}

const advisoryCount = computed(
    () => new Set(props.plan.flatMap((step) => step.fixes)).size,
);
</script>

<template>
    <section
        aria-labelledby="plan-heading"
        class="flex flex-col gap-3 rounded-lg border bg-card p-4 shadow-card"
    >
        <div>
            <h2 id="plan-heading" class="flex items-center gap-2 font-semibold">
                <Route
                    class="size-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Upgrade plan
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ plural(plan.length, 'change', 'changes') }} clear
                {{
                    plural(
                        advisoryCount,
                        'fixable advisory',
                        'fixable advisories',
                    )
                }}, worked out by resolving each chain against the npm registry
                the way a fresh install would. Cheapest first.
            </p>
            <div
                v-if="(canOpenPullRequest && changesManifest) || pullRequestUrl"
                class="mt-3 flex flex-wrap items-center gap-3"
            >
                <Button
                    v-if="canOpenPullRequest && changesManifest"
                    variant="outline"
                    size="sm"
                    :disabled="opening"
                    @click="openPullRequest"
                >
                    <GitPullRequest aria-hidden="true" />
                    {{ opening ? 'Opening PR…' : 'Open one PR for the plan' }}
                </Button>
                <a
                    v-if="pullRequestUrl"
                    :href="pullRequestUrl"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1.5 text-sm font-medium underline-offset-2 hover:underline"
                >
                    Plan PR
                    <ExternalLink class="size-3" aria-hidden="true" />
                    <span class="sr-only">(opens GitHub)</span>
                </a>
            </div>
        </div>

        <ol class="flex flex-col gap-3">
            <li
                v-for="step in plan"
                :key="`${step.action}:${step.package}`"
                class="flex flex-col gap-2 rounded-md border p-3 text-sm"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <component
                        :is="actionMeta[step.action].icon"
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <span class="font-medium">{{
                        actionMeta[step.action].label
                    }}</span>
                    <span class="font-mono break-all">
                        {{ step.package }}
                        <template v-if="step.from"
                            >{{ step.from }}
                            <span class="text-muted-foreground" aria-label="to"
                                >→</span
                            ></template
                        >
                        {{ step.to }}
                    </span>
                    <Badge
                        v-if="step.breaking"
                        variant="outline"
                        class="font-normal text-status-warning-foreground dark:text-status-warning"
                        title="Outside the caret range of the current version, so expect breaking changes"
                        >Major</Badge
                    >
                    <span class="ml-auto text-xs text-muted-foreground">
                        fixes {{ step.fixes.join(', ') }}
                    </span>
                </div>
                <p class="text-xs text-muted-foreground">
                    {{ actionMeta[step.action].hint }}
                    <template v-if="step.via.length > 1">
                        Chain:
                        <span class="font-mono">{{
                            step.via.join(' → ')
                        }}</span>
                    </template>
                </p>
                <div class="flex items-start gap-2">
                    <pre
                        class="min-w-0 flex-1 overflow-x-auto rounded-md bg-muted p-2.5 font-mono text-xs"
                    ><code>{{ command(step) }}</code></pre>
                    <Button
                        variant="outline"
                        size="icon"
                        :aria-label="
                            copied && copiedText === command(step)
                                ? 'Copied'
                                : 'Copy command'
                        "
                        @click="copy(command(step))"
                    >
                        <Check
                            v-if="copied && copiedText === command(step)"
                            aria-hidden="true"
                        />
                        <Copy v-else aria-hidden="true" />
                    </Button>
                </div>
            </li>
        </ol>
    </section>
</template>
