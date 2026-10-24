<script setup lang="ts">
import { EyeOff, FileCode2 } from '@lucide/vue';
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { plural } from '@/lib/format';
import type { Dependency, ImportSite } from '@/types';

/**
 * How a vulnerable package gets into the service: the shortest chain from the service's own
 * package.json drawn as a tree, other chains as one-liners, and whether the code ever reaches it.
 */
const props = defineProps<{
    dependency: Dependency;
    repository: string | null;
}>();

const SHOWN_SITES = 5;

const tree = computed(() => {
    const [first] = props.dependency.paths;

    return first && first.length > 1
        ? first.map((name, depth) => ({
              name,
              prefix: `${'   '.repeat(depth)}└─ `,
              target: depth === first.length - 1,
          }))
        : [];
});

const otherRoutes = computed(() => props.dependency.paths.slice(1));

const moreRoutes = computed(
    () => props.dependency.path_count - props.dependency.paths.length,
);

const usageLabel = computed(() => {
    const sites = props.dependency.import_sites.length;

    switch (props.dependency.usage) {
        case 'source':
            return `Imported in ${plural(sites, 'place', 'places')}`;
        case 'tooling':
            return sites > 0
                ? 'Only imported by config, tests or scripts'
                : 'Only run as a CLI';
        case 'unused':
            return 'Never imported';
        default:
            return null;
    }
});

function siteUrl(site: ImportSite): string | null {
    return props.repository
        ? `https://github.com/${props.repository}/blob/HEAD/${site.file
              .split('/')
              .map(encodeURIComponent)
              .join('/')}#L${site.line}`
        : null;
}
</script>

<template>
    <div
        v-if="
            tree.length ||
            dependency.direct ||
            dependency.reachable === false ||
            usageLabel
        "
        class="mt-3 flex flex-col gap-2 text-sm"
    >
        <div class="flex flex-wrap items-center gap-2">
            <Badge
                v-if="dependency.direct"
                variant="outline"
                class="font-normal"
                title="Listed in the service's own package.json"
                >Direct dependency</Badge
            >
            <Badge
                v-else-if="tree.length"
                variant="outline"
                class="font-normal"
                :title="`Not in package.json: ${plural(dependency.path_count, 'dependency', 'dependencies')} of the service pull it in`"
                >Transitive · {{ tree.length - 1 }}
                {{ tree.length === 2 ? 'hop' : 'hops' }} deep</Badge
            >
            <Badge
                v-if="dependency.reachable === false"
                variant="outline"
                class="gap-1 border-dashed font-normal"
                title="The usage scan found no import or CLI use of this package or of anything that pulls it in. Its fixable advisories warn instead of turning the service red."
            >
                <EyeOff aria-hidden="true" />
                Not reached by your code
            </Badge>
            <span
                v-if="usageLabel"
                class="inline-flex items-center gap-1 text-muted-foreground"
            >
                <FileCode2 class="size-3.5" aria-hidden="true" />
                {{ usageLabel }}
            </span>
        </div>

        <figure v-if="tree.length" class="rounded-md bg-muted/60 p-3">
            <figcaption class="sr-only">
                How {{ dependency.name }} gets into the service:
                {{ dependency.paths[0].join(' requires ') }}
            </figcaption>
            <ol
                class="overflow-x-auto font-mono text-xs leading-relaxed whitespace-pre"
                aria-hidden="true"
            >
                <li class="text-muted-foreground">your app</li>
                <li
                    v-for="node in tree"
                    :key="node.name"
                    :class="
                        node.target ? 'font-semibold' : 'text-muted-foreground'
                    "
                >
                    <span class="text-muted-foreground/70">{{
                        node.prefix
                    }}</span
                    ><span :class="{ 'text-foreground': !node.target }">{{
                        node.name
                    }}</span
                    ><span
                        v-if="node.target"
                        class="text-status-critical dark:text-status-critical"
                    >
                        {{ dependency.version }} ✗</span
                    >
                </li>
            </ol>
            <p
                v-if="otherRoutes.length || moreRoutes > 0"
                class="mt-2 border-t pt-2 font-mono text-xs text-muted-foreground"
            >
                <span v-for="route in otherRoutes" :key="route[0]" class="block"
                    >also via {{ route.join(' → ') }}</span
                >
                <span v-if="moreRoutes > 0" class="block"
                    >+
                    {{ plural(moreRoutes, 'more route', 'more routes') }}</span
                >
            </p>
        </figure>

        <ul
            v-if="dependency.import_sites.length"
            class="flex flex-col gap-0.5 font-mono text-xs"
            :aria-label="`Where the code imports ${dependency.name}`"
        >
            <li
                v-for="site in dependency.import_sites.slice(0, SHOWN_SITES)"
                :key="`${site.file}:${site.line}`"
                class="truncate"
            >
                <a
                    v-if="siteUrl(site)"
                    :href="siteUrl(site)!"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="underline-offset-2 hover:underline"
                    >{{ site.file }}:{{ site.line
                    }}<span class="sr-only"> (opens GitHub)</span></a
                >
                <template v-else>{{ site.file }}:{{ site.line }}</template>
                <span
                    v-if="site.kind === 'tooling'"
                    class="text-muted-foreground"
                >
                    · tooling</span
                >
            </li>
            <li
                v-if="dependency.import_sites.length > SHOWN_SITES"
                class="text-muted-foreground"
            >
                + {{ dependency.import_sites.length - SHOWN_SITES }} more
            </li>
        </ul>
    </div>
</template>
