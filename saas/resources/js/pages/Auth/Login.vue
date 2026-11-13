<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';
import StatusMessage from '../../components/StatusMessage.vue';

defineProps({
    status: { type: String, default: null },
    // Solo sulla demo: gli accessi pubblici per ruolo, con la password in chiaro (dati inventati).
    demoAccounts: { type: Array, default: () => [] },
});

const form = useForm({ email: '', password: '', remember: false });

const useAccount = (account) => {
    form.email = account.email;
    form.password = account.password;
};

const submit = () => form.post('/accesso', { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Accesso" />
    <GuestLayout title="Accedi" subtitle="Gestionale di Associazione Semplice">
        <StatusMessage :message="status" class="mb-4" />
        <section v-if="demoAccounts.length" class="mb-6 rounded border border-line p-4 text-sm" aria-labelledby="demo-accessi">
            <h2 id="demo-accessi" class="font-semibold">Accessi di prova</h2>
            <p class="caption mb-3">Dati inventati, azzerati ogni notte. Scegli un ruolo e i campi si compilano da soli.</p>
            <ul class="space-y-2">
                <li v-for="account in demoAccounts" :key="account.email" class="flex flex-wrap items-center justify-between gap-2">
                    <span><strong>{{ account.role }}</strong><br /><code>{{ account.email }}</code> · <code>{{ account.password }}</code></span>
                    <button type="button" class="font-semibold text-accent hover:underline" @click="useAccount(account)">Usa</button>
                </li>
            </ul>
        </section>
        <form class="space-y-5" novalidate @submit.prevent="submit">
            <FormField id="email" label="Email" :error="form.errors.email">
                <TextInput id="email" v-model="form.email" type="email" autocomplete="username" required autofocus :invalid="!!form.errors.email" />
            </FormField>
            <FormField id="password" label="Password" :error="form.errors.password">
                <TextInput id="password" v-model="form.password" type="password" autocomplete="current-password" required :invalid="!!form.errors.password" />
            </FormField>
            <label class="flex items-start gap-3 text-sm">
                <input v-model="form.remember" type="checkbox" class="mt-0.5" />
                <span>Ricordami su questo dispositivo<span class="caption block">Per 30 giorni. Su un computer non tuo lascia la casella vuota.</span></span>
            </label>
            <PrimaryButton :disabled="form.processing">Accedi</PrimaryButton>
        </form>
        <p class="mt-6 text-center text-sm">
            <Link href="/password-dimenticata" class="font-semibold text-accent hover:underline">Password dimenticata?</Link>
        </p>
    </GuestLayout>
</template>
