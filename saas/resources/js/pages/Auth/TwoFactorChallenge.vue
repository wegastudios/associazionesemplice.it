<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const useRecovery = ref(false);
const form = useForm({ code: '', recovery_code: '' });

const toggle = async () => {
    useRecovery.value = !useRecovery.value;
    form.reset();
    form.clearErrors();
    await nextTick();
    document.getElementById(useRecovery.value ? 'recovery_code' : 'code')?.focus();
};

const submit = () => form.post('/verifica-accesso', { onFinish: () => form.reset() });
</script>

<template>
    <Head title="Verifica in due passaggi" />
    <GuestLayout title="Verifica in due passaggi">
        <p class="mb-5 text-muted">
            <template v-if="!useRecovery">Scrivi il codice di 6 cifre che vedi nell'app di autenticazione.</template>
            <template v-else>Scrivi uno dei codici di recupero che hai salvato. Ogni codice vale una sola volta.</template>
        </p>
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField v-if="!useRecovery" id="code" label="Codice" :error="form.errors.code">
                <TextInput id="code" v-model="form.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" autofocus :invalid="!!form.errors.code" />
            </FormField>
            <FormField v-else id="recovery_code" label="Codice di recupero" :error="form.errors.recovery_code">
                <TextInput id="recovery_code" v-model="form.recovery_code" autocomplete="off" :invalid="!!form.errors.recovery_code" />
            </FormField>
            <PrimaryButton :disabled="form.processing">Verifica</PrimaryButton>
        </form>
        <button type="button" class="mt-6 w-full text-center text-sm font-semibold text-accent hover:underline" @click="toggle">
            {{ useRecovery ? 'Usa il codice dell\'app' : 'Non hai il telefono? Usa un codice di recupero' }}
        </button>
    </GuestLayout>
</template>
