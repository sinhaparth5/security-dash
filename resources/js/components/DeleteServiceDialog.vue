<script setup lang="ts">
import { Form } from '@inertiajs/vue3';
import { Trash2 } from '@lucide/vue';
import { ref, useId } from 'vue';
import ServiceController from '@/actions/App/Http/Controllers/ServiceController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

defineProps<{
    service: { id: number; name: string; environment: string };
    label?: string;
}>();

const id = useId();
const dialog = ref<HTMLDialogElement>();
const typed = ref('');

function open(): void {
    typed.value = '';
    dialog.value?.showModal();
}
</script>

<template>
    <div>
        <Button variant="destructive" size="sm" @click="open">
            <Trash2 aria-hidden="true" />
            {{ label ?? 'Delete service' }}
        </Button>
        <dialog
            ref="dialog"
            :aria-labelledby="`${id}-title`"
            class="m-auto w-[calc(100%-2rem)] max-w-md rounded-lg border bg-background p-0 text-foreground shadow-lg backdrop:bg-black/50"
        >
            <Form
                v-bind="ServiceController.destroy.form(service.id)"
                :options="{ preserveScroll: true }"
                class="flex flex-col gap-4 p-5"
                v-slot="{ errors, processing }"
                @success="dialog?.close()"
            >
                <h2 :id="`${id}-title`" class="font-semibold">
                    Delete {{ service.name }}
                    <span class="font-mono text-sm text-muted-foreground">{{
                        service.environment
                    }}</span
                    >?
                </h2>
                <p class="text-sm text-muted-foreground">
                    This deletes its dependencies, tokens, accepted risks and
                    status history. Reporters still using its tokens will get
                    401. The audit log keeps a record. This can't be undone.
                </p>
                <div class="flex flex-col gap-1.5">
                    <Label :for="`${id}-confirmation`"
                        >Type
                        <span class="font-mono">{{ service.name }}</span> to
                        confirm</Label
                    >
                    <Input
                        :id="`${id}-confirmation`"
                        v-model="typed"
                        name="confirmation"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-mono"
                    />
                    <InputError :message="errors.confirmation" />
                </div>
                <div class="flex justify-end gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        @click="dialog?.close()"
                        >Cancel</Button
                    >
                    <Button
                        type="submit"
                        variant="destructive"
                        :disabled="processing || typed !== service.name"
                        >{{ processing ? 'Deleting…' : 'Delete' }}</Button
                    >
                </div>
            </Form>
        </dialog>
    </div>
</template>
