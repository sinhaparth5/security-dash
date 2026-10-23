<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { KeyRound, TriangleAlert } from '@lucide/vue';
import { ref } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import InputError from '@/components/InputError.vue';
import ServiceConnection from '@/components/ServiceConnection.vue';
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

                <ServiceConnection :name="issued.name" :token="issued.token" />
            </div>

            <SheetFooter v-if="issued" class="border-t p-5">
                <Button type="button" @click="onOpenChange(false)">Done</Button>
            </SheetFooter>
        </SheetContent>
    </Sheet>
</template>
