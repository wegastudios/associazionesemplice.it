<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const form = useForm({ password: '' });
const submit = () => form.post('/conferma-password', { onFinish: () => form.reset() });
</script>

<template>
    <Head title="Conferma la password" />
    <GuestLayout title="Conferma la password">
        <p class="mb-5 text-muted">Per proseguire con un'operazione delicata, scrivi di nuovo la tua password.</p>
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField id="password" label="Password" :error="form.errors.password">
                <TextInput id="password" v-model="form.password" type="password" autocomplete="current-password" required autofocus :invalid="!!form.errors.password" />
            </FormField>
            <PrimaryButton :disabled="form.processing">Conferma</PrimaryButton>
        </form>
    </GuestLayout>
</template>
