<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { useClipboard } from '@vueuse/core';
import { Check, Copy, KeyRound, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';
import HeartbeatController from '@/actions/App/Http/Controllers/Api/V1/HeartbeatController';
import ImageScanController from '@/actions/App/Http/Controllers/Api/V1/ImageScanController';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetFooter,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import type { IssuedServiceToken } from '@/types';

const open = defineModel<boolean>('open', { required: true });

// Held locally: flash data is gone after the next poll, and the token is never shown again.
const issued = ref<IssuedServiceToken | null>(null);

// The browser's origin is the public URL services should call, even behind a proxy.
const heartbeatUrl = computed(
    () => window.location.origin + HeartbeatController.url(),
);
const imageScanUrl = computed(
    () => window.location.origin + ImageScanController.url(),
);

const fields = computed(() =>
    issued.value
        ? [
              { label: 'Token', value: issued.value.token, secret: true },
              { label: 'Heartbeat URL', value: heartbeatUrl.value },
              { label: 'Image scan URL', value: imageScanUrl.value },
          ]
        : [],
);

const curl = computed(
    () => `curl -X POST ${heartbeatUrl.value} \\
  -H "Authorization: Bearer $PATCHWATCH_TOKEN" \\
  -H "Content-Type: application/json" \\
  -H "Accept: application/json" \\
  -d '{"dependencies":[{"ecosystem":"runtime","name":"node","version":"v22.11.0"}]}'`,
);

const { copy, copied, text: copiedText } = useClipboard({ legacy: true });

function wasCopied(value: string): boolean {
    return copied.value && copiedText.value === value;
}

function onOpenChange(value: boolean): void {
    open.value = value;

    if (!value) {
        issued.value = null;
    }
}
</script>

<template>
    <Sheet :open="open" @update:open="onOpenChange">
        <SheetContent class="w-full gap-0 overflow-y-auto sm:max-w-lg">
            <SheetHeader class="border-b p-5">
                <SheetTitle class="text-lg">Register a service</SheetTitle>
                <SheetDescription>
                    Creates the service and issues an API token it uses to
                    report its dependencies.
                </SheetDescription>
            </SheetHeader>

            <Form
                v-if="!issued"
                v-bind="ServiceController.store.form()"
                :options="{ preserveScroll: true, preserveState: true }"
                :on-flash="(flash) => (issued = flash.serviceToken ?? issued)"
                class="flex flex-col gap-5 p-5"
                v-slot="{ errors, processing }"
            >
                <div class="grid gap-2">
                    <Label for="service-name">Service name</Label>
                    <Input
                        id="service-name"
                        name="name"
                        required
                        maxlength="100"
                        autocomplete="off"
                        placeholder="billing-api"
                        class="font-mono"
                    />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label for="service-environment">Environment</Label>
                    <Input
                        id="service-environment"
                        name="environment"
                        required
                        maxlength="50"
                        autocomplete="off"
                        placeholder="production"
                        class="font-mono"
                    />
                    <InputError :message="errors.environment" />
                    <p class="text-xs text-muted-foreground">
                        Already registered? This issues an extra token and
                        leaves the existing ones working.
                    </p>
                </div>

                <Button type="submit" :disabled="processing" class="self-start">
                    <KeyRound aria-hidden="true" />
                    {{
                        processing ? 'Issuing token…' : 'Create and issue token'
                    }}
                </Button>
            </Form>

            <div v-else class="flex flex-col gap-5 p-5">
                <div
                    role="alert"
                    class="flex gap-2.5 rounded-lg bg-status-warning-soft p-3 text-sm text-status-warning-foreground dark:text-status-warning"
                >
                    <TriangleAlert
                        class="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <p>
                        Copy the token now and store it as a secret for
                        <span class="font-mono font-medium"
                            >{{ issued.name }} ({{ issued.environment }})</span
                        >. It won't be shown again.
                    </p>
                </div>

                <div
                    v-for="field in fields"
                    :key="field.label"
                    class="grid gap-2"
                >
                    <Label :for="`issued-${field.label}`">{{
                        field.label
                    }}</Label>
                    <div class="flex gap-2">
                        <Input
                            :id="`issued-${field.label}`"
                            :model-value="field.value"
                            readonly
                            class="font-mono text-xs"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
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

                <div class="grid gap-2">
                    <div class="flex items-center justify-between">
                        <span class="text-sm font-medium"
                            >Example heartbeat</span
                        >
                        <Button
                            type="button"
                            variant="ghost"
                            size="sm"
                            @click="copy(curl)"
                        >
                            <Check
                                v-if="wasCopied(curl)"
                                class="text-status-ok"
                                aria-hidden="true"
                            />
                            <Copy v-else aria-hidden="true" />
                            Copy
                        </Button>
                    </div>
                    <pre
                        class="overflow-x-auto rounded-lg bg-muted p-3 font-mono text-xs leading-relaxed"
                    ><code>{{ curl }}</code></pre>
                    <p class="text-xs text-muted-foreground">
                        Send the full dependency list on every heartbeat. See
                        the README for the Trivy image-scan upload.
                    </p>
                </div>
            </div>

            <SheetFooter v-if="issued" class="border-t p-5">
                <Button type="button" @click="onOpenChange(false)">Done</Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
