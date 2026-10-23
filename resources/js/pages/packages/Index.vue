<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import { Package, Search } from '@lucide/vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import StatusBadge from '@/components/StatusBadge.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { packageUrl, plural } from '@/lib/format';
import { statusMeta } from '@/lib/status';
import { index as packagesIndex } from '@/routes/packages';
import type { FleetPackage } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Packages', href: packagesIndex() }],
    },
});

defineProps<{
    search: string;
    packages: FleetPackage[];
}>();
</script>

<template>
    <Head title="Packages" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header>
            <h1 class="text-[28px] font-bold tracking-tight lowercase">
                Packages
            </h1>
            <p class="mt-1 max-w-3xl text-sm text-muted-foreground">
                Every package across the fleet, with the services running each
                version. Search for a package when a new advisory lands to see
                who is affected.
            </p>
        </header>

        <Form
            v-bind="packagesIndex.form()"
            class="flex max-w-md gap-2"
            role="search"
        >
            <div class="relative flex-1">
                <Search
                    class="pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2 text-muted-foreground"
                    aria-hidden="true"
                />
                <Input
                    name="q"
                    type="search"
                    :default-value="search"
                    placeholder="lodash, golang.org/x/net…"
                    aria-label="Package name"
                    autocomplete="off"
                    spellcheck="false"
                    class="pl-8 font-mono"
                />
            </div>
            <Button type="submit" variant="outline">Search</Button>
        </Form>

        <section
            v-if="packages.length === 0"
            class="flex flex-col items-center gap-2 rounded-lg border border-dashed p-10 text-center"
        >
            <Package class="size-8 text-muted-foreground" aria-hidden="true" />
            <h2 class="font-medium">
                {{
                    search
                        ? `No service runs a package matching “${search}”`
                        : 'No dependencies reported yet'
                }}
            </h2>
        </section>

        <ul v-else class="grid gap-3 xl:grid-cols-2">
            <li
                v-for="pkg in packages"
                :key="`${pkg.ecosystem}|${pkg.name}`"
                class="rounded-lg border bg-card p-4 shadow-card"
            >
                <div
                    class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1"
                >
                    <span
                        class="flex flex-wrap items-center gap-2 font-medium break-all"
                    >
                        <a
                            v-if="packageUrl(pkg)"
                            :href="packageUrl(pkg)!"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="underline-offset-2 hover:underline"
                            >{{ pkg.name
                            }}<span class="sr-only">
                                (opens in a new tab)</span
                            ></a
                        >
                        <template v-else>{{ pkg.name }}</template>
                        <StatusBadge :status="pkg.status" />
                    </span>
                    <span class="font-mono text-xs text-muted-foreground"
                        >{{ pkg.ecosystem }} ·
                        {{
                            plural(pkg.services_count, 'service', 'services')
                        }}</span
                    >
                </div>
                <ul class="mt-3 space-y-2">
                    <li
                        v-for="version in pkg.versions"
                        :key="version.version"
                        class="flex flex-wrap items-baseline gap-x-3 gap-y-1 text-sm"
                    >
                        <span
                            class="inline-flex items-center gap-1 font-mono font-medium"
                            :class="statusMeta[version.status].text"
                        >
                            <component
                                :is="statusMeta[version.status].icon"
                                class="size-3.5"
                                aria-hidden="true"
                            />
                            <span class="sr-only"
                                >{{ statusMeta[version.status].label }}:</span
                            >
                            {{ version.version }}
                        </span>
                        <span class="flex flex-wrap gap-1.5">
                            <Link
                                v-for="service in version.services"
                                :key="service.id"
                                :href="ServiceController.show(service.id)"
                                class="rounded-sm focus-visible:ring-[3px] focus-visible:ring-ring/50 focus-visible:outline-none"
                            >
                                <Badge
                                    variant="outline"
                                    class="font-normal hover:bg-accent"
                                    >{{ service.name }}
                                    <span
                                        class="font-mono text-muted-foreground"
                                        >{{ service.environment }}</span
                                    ></Badge
                                >
                            </Link>
                        </span>
                    </li>
                </ul>
            </li>
        </ul>
    </div>
</template>
