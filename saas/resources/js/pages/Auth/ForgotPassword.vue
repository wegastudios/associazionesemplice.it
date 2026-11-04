<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';
import StatusMessage from '../../components/StatusMessage.vue';

defineProps({ status: { type: String, default: null } });

const form = useForm({ email: '' });
const submit = () => form.post('/password-dimenticata');
</script>

<template>
    <Head title="Password dimenticata" />
    <GuestLayout title="Password dimenticata">
        <p class="mb-5 text-muted">Scrivi la tua email: ti mandiamo un link per sceglierne una nuova.</p>
        <StatusMessage :message="status" class="mb-4" />
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email">
                <TextInput id="email" v-model="form.email" type="email" autocomplete="username" required autofocus :invalid="!!form.errors.email" />
            </FormField>
            <PrimaryButton :disabled="form.processing">Mandami il link</PrimaryButton>
        </form>
        <p class="mt-6 text-center text-sm">
            <Link href="/accesso" class="font-semibold text-accent hover:underline">Torna all'accesso</Link>
        </p>
    </GuestLayout>
</template>
