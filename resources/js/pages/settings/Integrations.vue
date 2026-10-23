<script setup lang="ts">
import { Form, Head, router } from '@inertiajs/vue3';
import { Bell, Brain, GitPullRequest, Send } from '@lucide/vue';
import { ref } from 'vue';
import IntegrationController from '@/actions/App/Http/Controllers/Settings/IntegrationController';
import InputError from '@/components/InputError.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/integrations';

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
}>();

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
</template>
