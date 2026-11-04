<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const props = defineProps({
    token: { type: String, required: true },
    email: { type: String, default: '' },
});

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const submit = () => form.post('/reimposta-password', { onFinish: () => form.reset('password', 'password_confirmation') });
</script>

<template>
    <Head title="Scegli la password" />
    <GuestLayout title="Scegli la password">
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email">
                <TextInput id="email" v-model="form.email" type="email" autocomplete="username" required :invalid="!!form.errors.email" />
            </FormField>
            <FormField id="password" label="Nuova password" hint="Almeno 12 caratteri. Una frase lunga è più sicura e più facile da ricordare." :error="form.errors.password">
                <TextInput id="password" v-model="form.password" type="password" autocomplete="new-password" required autofocus :invalid="!!form.errors.password" />
            </FormField>
            <FormField id="password_confirmation" label="Ripeti la password" :error="form.errors.password_confirmation">
                <TextInput id="password_confirmation" v-model="form.password_confirmation" type="password" autocomplete="new-password" required />
            </FormField>
            <PrimaryButton :disabled="form.processing">Salva la password</PrimaryButton>
        </form>
    </GuestLayout>
</template>
