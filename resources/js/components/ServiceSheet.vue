<script setup lang="ts">
import { ChevronDown, ExternalLink, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { lastSeen, plural } from '@/lib/format';
import { statusMeta, statusOrder } from '@/lib/status';
import type { Dependency, MonitoredService } from '@/types';

const props = defineProps<{
    service: MonitoredService | null;
}>();

defineEmits<{
    close: [];
}>();

const groups = computed(() =>
    statusOrder
        .map((status) => ({
            status,
            dependencies: (props.service?.dependencies ?? []).filter(
                (dependency) => dependency.status === status,
            ),
        }))
        .filter((group) => group.dependencies.length > 0),
);

// Healthy dependencies stay folded so the drawer opens on what needs action.
function startsOpen(status: Dependency['status']): boolean {
    return status === 'red' || status === 'yellow';
}

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
    <Sheet
        :open="service !== null"
        @update:open="(open) => !open && $emit('close')"
    >
        <SheetContent class="w-full gap-0 overflow-y-auto p-0 sm:max-w-xl">
            <template v-if="service">
                <SheetHeader class="gap-3 border-b p-5">
                    <div class="flex flex-wrap items-center gap-2 pr-8">
                        <SheetTitle class="text-lg">{{
                            service.name
                        }}</SheetTitle>
                        <Badge variant="outline" class="font-mono">{{
                            service.environment
                        }}</Badge>
                    </div>
                    <SheetDescription>
                        Last report
                        {{ lastSeen(service.last_ping_at).toLowerCase() }} ·
                        {{
                            plural(
                                service.dependencies.length,
                                'dependency',
                                'dependencies',
                            )
                        }}
                    </SheetDescription>
                    <StatusBadge :status="service.status" />
                </SheetHeader>

                <p
                    v-if="service.dependencies.length === 0"
                    class="p-5 text-sm text-muted-foreground"
                >
                    This service has not reported any dependencies yet.
                </p>

                <div class="divide-y">
                    <Collapsible
                        v-for="group in groups"
                        :key="group.status"
                        :default-open="startsOpen(group.status)"
                        class="group/section"
                    >
                        <CollapsibleTrigger
                            class="flex w-full cursor-pointer items-center gap-2 px-5 py-3 text-left text-sm font-medium transition-colors hover:bg-muted/50 focus-visible:bg-muted/50 focus-visible:outline-none"
                        >
                            <component
                                :is="statusMeta[group.status].icon"
                                class="size-4"
                                :class="statusMeta[group.status].text"
                                aria-hidden="true"
                            />
                            {{ statusMeta[group.status].label }}
                            <span class="text-muted-foreground tabular-nums">{{
                                group.dependencies.length
                            }}</span>
                            <ChevronDown
                                class="ml-auto size-4 text-muted-foreground transition-transform duration-200 group-data-[state=open]/section:rotate-180"
                                aria-hidden="true"
                            />
                        </CollapsibleTrigger>

                        <CollapsibleContent>
                            <ul class="space-y-2 px-5 pb-4">
                                <li
                                    v-for="dependency in group.dependencies"
                                    :key="dependency.id"
                                    class="rounded-lg border bg-card p-3"
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
                                        <span>{{ dependency.version }}</span>
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

                                    <ul
                                        v-if="dependency.advisories.length > 0"
                                        class="mt-3 space-y-2"
                                    >
                                        <li
                                            v-for="advisory in dependency.advisories"
                                            :key="advisory.id"
                                            class="rounded-md p-3 text-sm"
                                            :class="statusMeta.red.soft"
                                        >
                                            <div
                                                class="flex flex-wrap items-center gap-2"
                                            >
                                                <a
                                                    v-if="
                                                        advisoryUrl(
                                                            advisory.osv_id,
                                                        )
                                                    "
                                                    :href="
                                                        advisoryUrl(
                                                            advisory.osv_id,
                                                        )!
                                                    "
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
                                                    >{{
                                                        advisory.severity
                                                    }}</Badge
                                                >
                                            </div>
                                            <p
                                                v-if="advisory.aliases.length"
                                                class="mt-1 font-mono text-xs text-muted-foreground"
                                            >
                                                {{
                                                    advisory.aliases.join(', ')
                                                }}
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
                        </CollapsibleContent>
                    </Collapsible>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
