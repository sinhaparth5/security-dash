<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    ArrowRight,
    Clock,
    KeyRound,
    Network,
    MousePointerClick,
    Plus,
    Search,
    Trash2,
    TriangleAlert,
} from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import TokenController from '@/actions/App/Http/Controllers/TokenController';
import InputError from '@/components/InputError.vue';
import ServiceConnection from '@/components/ServiceConnection.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { lastSeen, plural } from '@/lib/format';
import { statusMeta } from '@/lib/status';
import { index as tokensIndex } from '@/routes/tokens';
import type {
    ServiceConnectionDetails,
    ServiceSummary,
    ServiceToken,
} from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Tokens',
                href: tokensIndex(),
            },
        ],
    },
});

const props = defineProps<{
    services: ServiceSummary[];
    selected: ServiceConnectionDetails | null;
}>();

const query = ref('');

const visible = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.services.filter(
        (service) =>
            needle === '' ||
            service.name.toLowerCase().includes(needle) ||
            service.environment.toLowerCase().includes(needle),
    );
});

function select(service: ServiceSummary) {
    return TokenController.index({ query: { service: service.id } });
}

// Held locally: flash data is gone after the next visit, and the token is never shown again.
const issuedToken = ref<string | null>(null);
const issuing = ref(false);
const expiresInDays = ref('');

const UNUSED_AFTER_DAYS = 30;

function isUnused(token: ServiceToken): boolean {
    const lastActivity = new Date(
        token.last_used_at ?? token.created_at ?? Date.now(),
    ).getTime();

    return Date.now() - lastActivity > UNUSED_AFTER_DAYS * 86_400_000;
}

function isExpired(token: ServiceToken): boolean {
    return (
        token.expires_at !== null &&
        new Date(token.expires_at).getTime() < Date.now()
    );
}

watch(
    () => props.selected?.id,
    () => (issuedToken.value = null),
);

function issueToken(service: ServiceConnectionDetails): void {
    router.post(
        TokenController.store.url(service.id),
        { expires_in_days: expiresInDays.value || null },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['services', 'selected'],
            onStart: () => (issuing.value = true),
            onFinish: () => (issuing.value = false),
            onFlash: (flash) =>
                (issuedToken.value = flash.serviceToken?.token ?? null),
        },
    );
}

const revoking = ref<number | null>(null);

function revoke(service: ServiceConnectionDetails, token: number): void {
    router.delete(TokenController.destroy.url({ service: service.id, token }), {
        preserveScroll: true,
        preserveState: true,
        only: ['services', 'selected'],
        onBefore: () =>
            confirm(
                `Revoke token #${token} for ${service.name}? Anything still using it gets 401 from its next request.`,
            ),
        onStart: () => (revoking.value = token),
        onFinish: () => (revoking.value = null),
    });
}
</script>

<template>
    <Head title="Tokens" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1 class="text-[28px] font-bold tracking-tight lowercase">
                Connection and tokens
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                Every service posts to the same URLs. The bearer token is what
                tells Patchwatch which service is reporting, so each service and
                environment gets its own.
            </p>
        </header>

        <div class="grid items-start gap-6 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <nav
                aria-label="Services"
                class="flex flex-col gap-2 lg:sticky lg:top-4"
            >
                <div class="relative">
                    <Search
                        class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <label for="token-service-search" class="sr-only"
                        >Search services</label
                    >
                    <Input
                        id="token-service-search"
                        v-model="query"
                        type="search"
                        placeholder="Search services"
                        class="pl-9"
                    />
                </div>

                <p
                    v-if="services.length === 0"
                    class="rounded-lg border border-dashed p-4 text-sm text-muted-foreground"
                >
                    No services yet. Register one from Service health.
                </p>

                <ul
                    v-else
                    class="flex max-h-[70vh] flex-col gap-1 overflow-y-auto rounded-lg border bg-card p-1.5 shadow-card"
                >
                    <li v-for="service in visible" :key="service.id">
                        <Link
                            :href="select(service)"
                            preserve-scroll
                            :aria-current="
                                selected?.id === service.id ? 'page' : undefined
                            "
                            class="flex items-center gap-3 rounded-lg px-3 py-2 text-sm hover:bg-accent focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none aria-[current=page]:bg-accent aria-[current=page]:font-medium"
                        >
                            <component
                                :is="statusMeta[service.status].icon"
                                class="size-4 shrink-0"
                                :class="statusMeta[service.status].text"
                                :aria-label="statusMeta[service.status].label"
                            />
                            <span class="min-w-0 flex-1">
                                <span class="block truncate">{{
                                    service.name
                                }}</span>
                                <span
                                    class="block truncate font-mono text-xs font-normal text-muted-foreground"
                                    >{{ service.environment }}</span
                                >
                            </span>
                            <span
                                class="flex items-center gap-1 text-xs text-muted-foreground tabular-nums"
                                :aria-label="
                                    plural(
                                        service.tokens_count,
                                        'token',
                                        'tokens',
                                    )
                                "
                            >
                                <KeyRound class="size-3" aria-hidden="true" />
                                {{ service.tokens_count }}
                            </span>
                        </Link>
                    </li>
                    <li
                        v-if="visible.length === 0"
                        class="px-3 py-4 text-center text-sm text-muted-foreground"
                    >
                        No services match.
                    </li>
                </ul>
            </nav>

            <section
                v-if="!selected"
                class="flex min-h-64 flex-col items-center justify-center gap-2 rounded-lg border border-dashed p-10 text-center"
            >
                <MousePointerClick
                    class="size-8 text-muted-foreground"
                    aria-hidden="true"
                />
                <h2 class="font-medium">Select a service</h2>
                <p class="max-w-sm text-sm text-muted-foreground">
                    Pick a service on the left to see its URLs, issue a new
                    token or revoke old ones.
                </p>
            </section>

            <section
                v-else
                :aria-label="`${selected.name} connection`"
                class="flex min-w-0 flex-col gap-6"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="flex min-w-0 flex-wrap items-center gap-3">
                        <h2 class="text-xl font-semibold break-all">
                            {{ selected.name }}
                        </h2>
                        <Badge variant="outline" class="font-mono">{{
                            selected.environment
                        }}</Badge>
                        <StatusBadge :status="selected.status" />
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Button variant="outline" as-child>
                            <Link :href="ServiceController.show(selected.id)">
                                Dependencies
                                <ArrowRight aria-hidden="true" />
                            </Link>
                        </Button>
                        <label for="token-expiry" class="sr-only"
                            >New token expires</label
                        >
                        <select
                            id="token-expiry"
                            v-model="expiresInDays"
                            class="h-9 rounded-md border border-input bg-transparent px-3 text-sm shadow-card focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                        >
                            <option value="">Never expires</option>
                            <option value="30">Expires in 30 days</option>
                            <option value="90">Expires in 90 days</option>
                            <option value="180">Expires in 180 days</option>
                            <option value="365">Expires in 1 year</option>
                        </select>
                        <Button
                            :disabled="issuing"
                            @click="issueToken(selected)"
                        >
                            <Plus aria-hidden="true" />
                            {{ issuing ? 'Issuing token…' : 'Issue new token' }}
                        </Button>
                    </div>
                </div>

                <p class="-mt-3 text-sm text-muted-foreground">
                    Last report
                    {{ lastSeen(selected.last_ping_at).toLowerCase() }}. To
                    rotate, issue a new token, deploy it, then revoke the old
                    one once its "last used" stops moving.
                </p>

                <div
                    v-if="issuedToken"
                    role="alert"
                    class="flex gap-2.5 rounded-lg bg-status-warning-soft p-3 text-sm text-status-warning-foreground dark:text-status-warning"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Copy the new token now and store it as this service's
                        secret. It won't be shown again.
                    </p>
                </div>

                <div class="rounded-lg border bg-card p-4 shadow-card">
                    <ServiceConnection
                        :name="selected.name"
                        :token="issuedToken"
                    />
                </div>

                <div class="flex flex-col gap-3">
                    <h3 class="flex items-center gap-2 font-semibold">
                        <KeyRound
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        Active tokens
                        <span
                            class="font-normal text-muted-foreground tabular-nums"
                            >{{ selected.tokens.length }}</span
                        >
                    </h3>
                    <p
                        v-if="selected.tokens.length === 0"
                        class="text-sm text-muted-foreground"
                    >
                        No active tokens. This service can't report until you
                        issue one.
                    </p>
                    <ul v-else class="grid gap-2 xl:grid-cols-2">
                        <li
                            v-for="token in selected.tokens"
                            :key="token.id"
                            class="flex items-center justify-between gap-3 rounded-lg border bg-card p-3 text-sm"
                        >
                            <div>
                                <div
                                    class="flex flex-wrap items-center gap-2 font-mono font-medium"
                                >
                                    #{{ token.id }}
                                    <Badge
                                        v-if="isExpired(token)"
                                        variant="outline"
                                        class="font-sans"
                                        :class="statusMeta.red.text"
                                        >Expired</Badge
                                    >
                                    <Badge
                                        v-else-if="isUnused(token)"
                                        variant="outline"
                                        class="gap-1 font-sans"
                                        :class="statusMeta.yellow.text"
                                    >
                                        <Clock aria-hidden="true" />
                                        Unused {{ UNUSED_AFTER_DAYS }}+ days
                                    </Badge>
                                </div>
                                <div class="text-xs text-muted-foreground">
                                    Created
                                    {{
                                        lastSeen(token.created_at).toLowerCase()
                                    }}
                                    · last used
                                    {{
                                        token.last_used_at
                                            ? lastSeen(
                                                  token.last_used_at,
                                              ).toLowerCase()
                                            : 'never'
                                    }}
                                    ·
                                    {{
                                        token.expires_at
                                            ? `expires ${lastSeen(token.expires_at).toLowerCase()}`
                                            : 'never expires'
                                    }}
                                </div>
                            </div>
                            <Button
                                variant="outline"
                                size="sm"
                                class="text-status-critical hover:text-status-critical"
                                :disabled="revoking === token.id"
                                @click="revoke(selected, token.id)"
                            >
                                <Trash2 aria-hidden="true" />
                                {{
                                    revoking === token.id
                                        ? 'Revoking…'
                                        : 'Revoke'
                                }}
                            </Button>
                        </li>
                    </ul>
                    <p
                        v-if="selected.tokens.some(isUnused)"
                        class="text-sm text-muted-foreground"
                    >
                        Tokens unused for {{ UNUSED_AFTER_DAYS }} days are
                        likely left over from an old deploy. Revoke them unless
                        you know where they live.
                    </p>
                </div>

                <Form
                    :key="selected.id"
                    v-bind="TokenController.updateAllowedIps.form(selected.id)"
                    :options="{
                        preserveScroll: true,
                        preserveState: true,
                        only: ['selected'],
                    }"
                    class="flex flex-col gap-3"
                    v-slot="{ errors, processing }"
                >
                    <h3 class="flex items-center gap-2 font-semibold">
                        <Network
                            class="size-4 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Label for="allowed-ips" class="text-base"
                            >IP allowlist</Label
                        >
                    </h3>
                    <p
                        id="allowed-ips-help"
                        class="-mt-1 text-sm text-muted-foreground"
                    >
                        IPs or CIDR ranges this service reports from, one per
                        line. Requests from anywhere else get 403 even with a
                        valid token. Leave empty to allow any address.
                    </p>
                    <textarea
                        id="allowed-ips"
                        name="allowed_ips"
                        rows="3"
                        aria-describedby="allowed-ips-help"
                        :value="selected.allowed_ips.join('\n')"
                        placeholder="10.0.0.0/8"
                        class="w-full max-w-md rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-card focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none dark:bg-input/30"
                    />
                    <InputError
                        :message="
                            errors.allowed_ips ??
                            Object.entries(errors).find(([key]) =>
                                key.startsWith('allowed_ips.'),
                            )?.[1]
                        "
                    />
                    <Button
                        type="submit"
                        variant="outline"
                        class="w-fit"
                        :disabled="processing"
                    >
                        {{ processing ? 'Saving…' : 'Save allowlist' }}
                    </Button>
                </Form>
            </section>
        </div>
    </div>
</template>
