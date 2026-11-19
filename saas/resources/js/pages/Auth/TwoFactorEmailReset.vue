<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const props = defineProps({ action: { type: String, required: true } });

const form = useForm({});
const submit = () => form.post(props.action);
</script>

<template>
    <Head title="Disattiva la verifica in due passaggi" />
    <GuestLayout title="Disattiva la verifica in due passaggi">
        <p class="mb-5 text-muted">
            Se confermi, la verifica in due passaggi del tuo accesso viene tolta. Al prossimo accesso riattivala subito, con il telefono nuovo.
        </p>
        <form @submit.prevent="submit">
            <PrimaryButton :disabled="form.processing">Disattiva la verifica</PrimaryButton>
        </form>
        <Link href="/accesso" class="mt-6 block w-full text-center text-sm font-semibold text-accent hover:underline">Torna all'accesso</Link>
    </GuestLayout>
</template>
