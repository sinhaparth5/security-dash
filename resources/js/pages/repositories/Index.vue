<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { Bot, ExternalLink, Eye, FolderGit2, Lock } from '@lucide/vue';
import { ref } from 'vue';
import RepositoryController from '@/actions/App/Http/Controllers/RepositoryController';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import DeleteServiceDialog from '@/components/DeleteServiceDialog.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { edit as integrationsEdit } from '@/routes/integrations';
import { index as repositoriesIndex } from '@/routes/repositories';
import type { WatchableRepository } from '@/types';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Repositories', href: repositoriesIndex() }],
    },
});

defineProps<{
    app: { enabled: boolean; install_url: string | null };
    repositories?: WatchableRepository[];
}>();

const watching = ref<string | null>(null);

function watch(repository: WatchableRepository): void {
    router.post(
        RepositoryController.store.url(),
        { repository: repository.full_name },
        {
            onStart: () => (watching.value = repository.full_name),
            onFinish: () => (watching.value = null),
        },
    );
}
</script>

<template>
    <Head title="Repositories" />

    <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-[28px] font-bold tracking-tight">
                    Repositories
                </h1>
                <p class="mt-1 max-w-2xl text-sm text-muted-foreground">
                    Watch a repository and patchwatch-bot grades it from its
                    lockfiles and imports on every push to the default branch,
                    with no reporter to install. Every pull request gets a check
                    and a dependency review comment.
                </p>
            </div>
            <Button v-if="app.install_url" variant="outline" size="sm" as-child>
                <a
                    :href="app.install_url"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <Bot aria-hidden="true" />
                    Add repositories on GitHub
                </a>
            </Button>
        </header>

        <div
            v-if="!app.enabled"
            class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
        >
            patchwatch-bot isn't set up yet.
            <Link
                :href="integrationsEdit()"
                class="font-medium text-foreground underline-offset-2 hover:underline"
                >Create it in Settings</Link
            >, then install it on the repositories to watch.
        </div>

        <Deferred v-else data="repositories">
            <template #fallback>
                <ul class="flex flex-col gap-2" aria-busy="true">
                    <li
                        v-for="row in 4"
                        :key="row"
                        class="h-14 animate-pulse rounded-lg border bg-muted/50"
                    />
                </ul>
            </template>

            <p
                v-if="!repositories?.length"
                class="rounded-lg border border-dashed p-10 text-center text-sm text-muted-foreground"
            >
                patchwatch-bot isn't installed on any repository yet.
            </p>
            <ul v-else class="flex flex-col gap-2">
                <li
                    v-for="repository in repositories"
                    :key="repository.full_name"
                    class="flex flex-wrap items-center gap-3 rounded-lg border bg-card p-3 shadow-card"
                >
                    <FolderGit2
                        class="size-4 text-muted-foreground"
                        aria-hidden="true"
                    />
                    <a
                        :href="repository.html_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="inline-flex items-center gap-1 font-mono text-sm font-medium break-all underline-offset-2 hover:underline"
                        >{{ repository.full_name
                        }}<ExternalLink
                            class="size-3"
                            aria-hidden="true"
                        /><span class="sr-only"> (opens GitHub)</span></a
                    >
                    <Badge
                        v-if="repository.private"
                        variant="outline"
                        class="gap-1 font-normal"
                    >
                        <Lock aria-hidden="true" />
                        Private
                    </Badge>
                    <div
                        v-if="repository.service"
                        class="ml-auto flex flex-wrap gap-2"
                    >
                        <Button variant="outline" size="sm" as-child>
                            <Link
                                :href="
                                    ServiceController.show(
                                        repository.service.id,
                                    )
                                "
                            >
                                <Eye aria-hidden="true" />
                                Watching
                            </Link>
                        </Button>
                        <DeleteServiceDialog
                            :service="repository.service"
                            label="Delete"
                        />
                    </div>
                    <Button
                        v-else
                        size="sm"
                        class="ml-auto"
                        :disabled="watching !== null"
                        @click="watch(repository)"
                    >
                        {{
                            watching === repository.full_name
                                ? 'Adding…'
                                : 'Watch'
                        }}
                    </Button>
                </li>
            </ul>
        </Deferred>
    </div>
</template>
