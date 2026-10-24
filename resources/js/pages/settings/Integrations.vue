<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import {
    Bell,
    Bot,
    Brain,
    ExternalLink,
    GitPullRequest,
    Send,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import IntegrationController from '@/actions/App/Http/Controllers/Settings/IntegrationController';
import InputError from '@/components/InputError.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/integrations';
import { index as repositoriesIndex } from '@/routes/repositories';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Integrations', href: edit() }],
    },
});

type Field =
    | 'webhook_url'
    | 'typesafe_key'
    | 'typesafe_model'
    | 'github_pr_token';

const props = defineProps<{
    integrations: Record<
        Field,
        { source: 'saved' | 'env' | null; value: string | null }
    >;
    githubApp: {
        enabled: boolean;
        slug: string | null;
        install_url: string | null;
        settings_url: string | null;
        create_url: string;
        manifest: string;
        logo_url: string;
    };
}>();

const webhookUrl = computed<string>(
    () => JSON.parse(props.githubApp.manifest).hook_attributes.url,
);

function forgetGithubApp() {
    router.delete(IntegrationController.forgetGithubApp.url(), {
        preserveScroll: true,
        onBefore: () =>
            confirm(
                'Disconnect patchwatch-bot? Checks and PR reviews stop until it is connected again.',
            ),
    });
}

const groups: {
    title: string;
    icon: typeof Bell;
    fields: {
        name: Field;
        label: string;
        help: string;
        type: 'password' | 'url' | 'text';
        placeholder: string;
    }[];
}[] = [
    {
        title: 'alerts',
        icon: Bell,
        fields: [
            {
                name: 'webhook_url',
                label: 'Slack or Discord webhook',
                help: 'Gets the same message as the Red alert mail. Must be https.',
                type: 'password',
                placeholder: 'https://hooks.slack.com/services/…',
            },
        ],
    },
    {
        title: 'jev triage',
        icon: Brain,
        fields: [
            {
                name: 'typesafe_key',
                label: 'TypeSafe API key',
                help: 'Turns on advisory triage and news ranking. Leave unset to skip both.',
                type: 'password',
                placeholder: 'ts_…',
            },
            {
                name: 'typesafe_model',
                label: 'Model',
                help: 'Defaults to jev-latest.',
                type: 'text',
                placeholder: 'jev-latest',
            },
        ],
    },
    {
        title: 'github pull requests',
        icon: GitPullRequest,
        fields: [
            {
                name: 'github_pr_token',
                label: 'Fine-grained token',
                help: 'Needs Contents and Pull requests write access on the service repositories.',
                type: 'password',
                placeholder: 'github_pat_…',
            },
        ],
    },
];

const sourceLabel = {
    saved: 'saved here',
    env: 'from .env',
} as const;

const testing = ref(false);

function testWebhook() {
    router.post(
        IntegrationController.testWebhook.url(),
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );
}
</script>

<template>
    <Head title="Integrations" />

    <h1 class="sr-only">Integrations</h1>

    <Form
        v-bind="IntegrationController.update.form()"
        class="flex flex-col gap-4"
        :options="{ preserveScroll: true }"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <p class="text-sm text-muted-foreground">
            Values saved here are encrypted and win over <code>.env</code>.
            Secrets only ever come back as their last four characters, so leave
            a field blank to keep it.
        </p>

        <Panel
            v-for="group in groups"
            :key="group.title"
            :title="group.title"
            :icon="group.icon"
        >
            <template v-if="group.title === 'alerts'" #action>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="testing || !props.integrations.webhook_url.value"
                    @click="testWebhook"
                >
                    <Send class="size-3.5" aria-hidden="true" />
                    Send test
                </Button>
            </template>

            <div
                v-for="field in group.fields"
                :key="field.name"
                class="grid gap-2"
            >
                <div
                    class="flex flex-wrap items-baseline justify-between gap-2"
                >
                    <Label :for="field.name">{{ field.label }}</Label>
                    <span class="text-xs text-muted-foreground">
                        <template v-if="props.integrations[field.name].source">
                            {{
                                sourceLabel[
                                    props.integrations[field.name].source!
                                ]
                            }}
                            ·
                            <code>{{
                                props.integrations[field.name].value
                            }}</code>
                        </template>
                        <template v-else>not set</template>
                    </span>
                </div>
                <Input
                    :id="field.name"
                    :name="field.name"
                    :type="field.type"
                    :placeholder="field.placeholder"
                    :aria-describedby="`${field.name}-help`"
                    autocomplete="off"
                    spellcheck="false"
                />
                <p
                    :id="`${field.name}-help`"
                    class="text-xs text-muted-foreground"
                >
                    {{ field.help }}
                </p>
                <label
                    v-if="props.integrations[field.name].source === 'saved'"
                    class="flex w-fit cursor-pointer items-center gap-2 text-xs"
                >
                    <input
                        type="checkbox"
                        name="clear[]"
                        :value="field.name"
                        class="size-4 accent-brand"
                    />
                    Remove the saved value{{
                        field.name === 'typesafe_model'
                            ? ''
                            : ' (falls back to .env)'
                    }}
                </label>
                <InputError :message="errors[field.name]" />
            </div>
        </Panel>

        <InputError :message="errors['clear.0']" />

        <div>
            <Button :disabled="processing">Save integrations</Button>
        </div>
    </Form>

    <!-- Outside the form above: creating the app posts a manifest to GitHub. -->
    <Panel title="patchwatch-bot" :icon="Bot" class="mt-4">
        <template v-if="githubApp.enabled">
            <p class="text-sm leading-relaxed">
                Connected as
                <a
                    :href="githubApp.settings_url!"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="inline-flex items-center gap-1 font-medium underline-offset-2 hover:underline"
                    >{{ githubApp.slug
                    }}<ExternalLink class="size-3" aria-hidden="true" /><span
                        class="sr-only"
                    >
                        (opens GitHub)</span
                    ></a
                >. It posts a check on every push and pull request, keeps one
                dependency review comment up to date on each PR, and opens fix
                PRs as itself.
            </p>
            <div class="flex flex-wrap gap-2">
                <Button size="sm" as-child>
                    <a
                        :href="githubApp.install_url!"
                        target="_blank"
                        rel="noopener noreferrer"
                        >Install on repositories</a
                    >
                </Button>
                <Button variant="outline" size="sm" as-child>
                    <Link :href="repositoriesIndex()">Watch a repository</Link>
                </Button>
                <Button variant="ghost" size="sm" @click="forgetGithubApp"
                    >Disconnect</Button
                >
            </div>
            <div class="flex items-center gap-3 border-t pt-4">
                <img
                    :src="githubApp.logo_url"
                    alt=""
                    width="48"
                    height="48"
                    class="size-12 rounded-lg"
                />
                <p class="text-xs leading-relaxed text-muted-foreground">
                    GitHub doesn't let an app set its own picture.
                    <a
                        :href="githubApp.logo_url"
                        download="patchwatch-bot.png"
                        class="font-medium text-foreground underline-offset-2 hover:underline"
                        >Download the logo</a
                    >, then upload it under Display information in the app's
                    settings so the bot's avatar is the Patchwatch icon.
                </p>
            </div>
        </template>
        <form
            v-else
            method="post"
            :action="githubApp.create_url"
            class="flex flex-col gap-3"
        >
            <input type="hidden" name="manifest" :value="githubApp.manifest" />
            <p class="text-sm leading-relaxed">
                Create Patchwatch's own GitHub App. GitHub asks you to confirm
                its name and permissions (checks, contents and pull requests),
                then sends you back here with it connected.
            </p>
            <p class="text-xs text-muted-foreground">
                Its webhook points at
                <code class="break-all">{{ webhookUrl }}</code
                >, which GitHub has to be able to reach.
            </p>
            <Button type="submit" size="sm" class="w-fit">
                <Bot aria-hidden="true" />
                Create the GitHub App
            </Button>
        </form>
    </Panel>
</template>
