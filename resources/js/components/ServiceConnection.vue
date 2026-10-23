<script setup lang="ts">
import { useClipboard } from '@vueuse/core';
import { Check, Copy } from '@lucide/vue';
import { computed } from 'vue';
import HeartbeatController from '@/actions/App/Http/Controllers/Api/V1/HeartbeatController';
import ImageScanController from '@/actions/App/Http/Controllers/Api/V1/ImageScanController';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    name: string;
    token?: string | null;
}>();

// The browser's origin is the public URL services should call, even behind a proxy.
const heartbeatUrl = computed(
    () => window.location.origin + HeartbeatController.url(),
);
const imageScanUrl = computed(
    () => window.location.origin + ImageScanController.url(),
);

const fields = computed(() => [
    ...(props.token ? [{ label: 'Token', value: props.token }] : []),
    { label: 'Heartbeat URL', value: heartbeatUrl.value },
    { label: 'Image scan URL', value: imageScanUrl.value },
]);

const headers = `-H "Authorization: Bearer $PATCHWATCH_TOKEN" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json"`;

const examples = computed(() => [
    {
        label: 'Example heartbeat',
        code: `curl -X POST ${heartbeatUrl.value} \\
  ${headers} \\
  -d '{"dependencies":[{"ecosystem":"runtime","name":"node","version":"v22.11.0"}]}'`,
        hint: 'Send the full dependency list on every heartbeat.',
    },
    {
        label: 'Example image scan (CI)',
        code: `trivy image --format json --severity HIGH,CRITICAL --ignore-unfixed \\
  registry.example.com/${props.name}:1.0.0 > scan.json

curl -X POST ${imageScanUrl.value} \\
  ${headers} \\
  --data-binary @scan.json`,
        hint: 'Any finding turns the image red, so filter severity in the scan.',
    },
]);

const { copy, copied, text: copiedText } = useClipboard({ legacy: true });

function wasCopied(value: string): boolean {
    return copied.value && copiedText.value === value;
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div v-for="field in fields" :key="field.label" class="grid gap-2">
            <Label :for="`connection-${field.label}`">{{ field.label }}</Label>
            <div class="flex gap-2">
                <Input
                    :id="`connection-${field.label}`"
                    :model-value="field.value"
                    readonly
                    class="font-mono text-xs"
                    @focus="($event.target as HTMLInputElement).select()"
                />
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    :aria-label="`Copy ${field.label.toLowerCase()}`"
                    @click="copy(field.value)"
                >
                    <Check
                        v-if="wasCopied(field.value)"
                        class="text-status-ok"
                        aria-hidden="true"
                    />
                    <Copy v-else aria-hidden="true" />
                </Button>
            </div>
        </div>

        <div
            v-for="example in examples"
            :key="example.label"
            class="grid min-w-0 gap-2"
        >
            <div class="flex items-center justify-between">
                <span class="text-sm font-medium">{{ example.label }}</span>
                <Button
                    type="button"
                    variant="ghost"
                    size="sm"
                    :aria-label="`Copy ${example.label.toLowerCase()}`"
                    @click="copy(example.code)"
                >
                    <Check
                        v-if="wasCopied(example.code)"
                        class="text-status-ok"
                        aria-hidden="true"
                    />
                    <Copy v-else aria-hidden="true" />
                    Copy
                </Button>
            </div>
            <pre
                class="overflow-x-auto rounded-lg bg-muted p-3 font-mono text-xs leading-relaxed"
            ><code>{{ example.code }}</code></pre>
            <p class="text-xs text-muted-foreground">{{ example.hint }}</p>
        </div>
    </div>
</template>
