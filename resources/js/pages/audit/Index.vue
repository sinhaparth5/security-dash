<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, ScrollText } from '@lucide/vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import AuditTrace from '@/components/ascii/AuditTrace.vue';
import { Button } from '@/components/ui/button';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { lastSeen } from '@/lib/format';
import { index as auditIndex } from '@/routes/audit';
import type { AuditEvent } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Audit log', href: auditIndex() }],
    },
});

defineProps<{
    events: {
        data: AuditEvent[];
        prev_page_url: string | null;
        next_page_url: string | null;
    };
}>();

const labels: Record<string, string> = {
    'service.registered': 'Registered service',
    'service.allowed_ips_updated': 'Changed IP allowlist',
    'token.issued': 'Issued token',
    'token.revoked': 'Revoked token',
    'risk.accepted': 'Accepted risk',
    'risk.revoked': 'Removed risk acceptance',
    'service.repository_updated': 'Linked GitHub repository',
    'pull_request.opened': 'Opened upgrade PR',
    'service.badge_rotated': 'Rotated status badge URL',
    'settings.updated': 'Changed integrations',
    'user.login': 'Signed in',
    'user.login_denied': 'Sign-in denied',
};

function describe(event: AuditEvent): string {
    const details = event.details ?? {};

    return Object.entries(details)
        .filter(([, value]) => value !== null && value !== '')
        .map(
            ([key, value]) =>
                `${key.replaceAll('_', ' ')}: ${Array.isArray(value) ? value.join(', ') || 'none' : String(value)}`,
        )
        .join(' · ');
}
</script>

<template>
    <Head title="Audit log" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1 class="text-[28px] font-bold tracking-tight lowercase">
                Audit log
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                Every sign-in, service registration, token change, allowlist
                edit and accepted risk, newest first. Use it to trace a leaked
                token back to who issued it.
            </p>
        </header>

        <AuditTrace
            v-if="events.data.length > 0"
            :events="events.data"
            :labels="labels"
        />

        <section
            v-if="events.data.length === 0"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-center"
        >
            <ScrollText
                class="size-8 text-muted-foreground"
                aria-hidden="true"
            />
            <h2 class="font-medium">Nothing recorded yet</h2>
        </section>

        <div
            v-else
            class="overflow-hidden rounded-lg border bg-card shadow-card"
        >
            <Table>
                <TableHeader>
                    <TableRow class="hover:bg-transparent">
                        <TableHead class="pl-4">When</TableHead>
                        <TableHead>Who</TableHead>
                        <TableHead>What</TableHead>
                        <TableHead class="hidden md:table-cell"
                            >Service</TableHead
                        >
                        <TableHead class="hidden pr-4 lg:table-cell"
                            >IP</TableHead
                        >
                    </TableRow>
                </TableHeader>
                <TableBody>
                    <TableRow v-for="event in events.data" :key="event.id">
                        <TableCell class="py-3 pl-4 text-sm whitespace-nowrap">
                            <time
                                :datetime="event.created_at"
                                :title="
                                    new Date(event.created_at).toLocaleString()
                                "
                                >{{ lastSeen(event.created_at) }}</time
                            >
                        </TableCell>
                        <TableCell class="text-sm">{{
                            event.user ?? 'Console'
                        }}</TableCell>
                        <TableCell class="text-sm whitespace-normal">
                            <span class="font-medium">{{
                                labels[event.action] ?? event.action
                            }}</span>
                            <span
                                v-if="describe(event)"
                                class="mt-0.5 block font-mono text-xs break-all text-muted-foreground"
                                >{{ describe(event) }}</span
                            >
                        </TableCell>
                        <TableCell class="hidden text-sm md:table-cell">
                            <Link
                                v-if="event.service"
                                :href="ServiceController.show(event.service.id)"
                                class="rounded-sm underline-offset-2 hover:underline focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                {{ event.service.name }}
                                <span
                                    class="font-mono text-xs text-muted-foreground"
                                    >{{ event.service.environment }}</span
                                >
                            </Link>
                            <span v-else class="text-muted-foreground">—</span>
                        </TableCell>
                        <TableCell
                            class="hidden pr-4 font-mono text-xs text-muted-foreground lg:table-cell"
                            >{{ event.ip ?? '—' }}</TableCell
                        >
                    </TableRow>
                </TableBody>
            </Table>
        </div>

        <nav
            v-if="events.prev_page_url || events.next_page_url"
            aria-label="Audit log pages"
            class="flex justify-between gap-2"
        >
            <Button
                v-if="events.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="events.prev_page_url" preserve-scroll>
                    <ChevronLeft aria-hidden="true" />
                    Newer
                </Link>
            </Button>
            <span v-else />
            <Button
                v-if="events.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="events.next_page_url" preserve-scroll>
                    Older
                    <ChevronRight aria-hidden="true" />
                </Link>
            </Button>
        </nav>
    </div>
</template>
