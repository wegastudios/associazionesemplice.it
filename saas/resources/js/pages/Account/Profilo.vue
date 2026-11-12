<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';
import StatusMessage from '../../components/StatusMessage.vue';

const props = defineProps({
    profile: { type: Object, required: true },
    twoFactor: Boolean,
    sessions: { type: Array, default: () => [] },
});

const page = usePage();
const status = computed(() => page.props.flash?.status ?? page.props.status ?? null);

const nameForm = useForm({ name: props.profile.name });
const emailForm = useForm({ email: props.profile.email, password: '' });
const passwordForm = useForm({ current_password: '', password: '', password_confirmation: '' });
const othersForm = useForm({ password: '' });

const saveName = () => nameForm.put('/gestionale/profilo/nome', { preserveScroll: true });
const saveEmail = () => emailForm.put('/gestionale/profilo/email', { preserveScroll: true, onSuccess: () => emailForm.reset('password') });
const savePassword = () => passwordForm.put('/user/password', { errorBag: 'updatePassword', preserveScroll: true, onSuccess: () => passwordForm.reset() });
const closeOthers = () => othersForm.post('/gestionale/profilo/dispositivi', { preserveScroll: true, onSuccess: () => othersForm.reset() });
const when = (iso) => new Date(iso).toLocaleString('it-IT', { dateStyle: 'medium', timeStyle: 'short' });
</script>

<template>
    <Head title="Il tuo profilo" />
    <AppLayout>
        <h1 class="mb-5 text-2xl font-bold">Il tuo profilo</h1>
        <StatusMessage :message="status" class="mb-5" />

        <div class="space-y-6">
            <section class="card p-4 sm:p-5 space-y-4" aria-labelledby="p-nome">
                <h2 id="p-nome" class="text-lg font-bold">Nome</h2>
                <form class="space-y-4" novalidate @submit.prevent="saveName">
                    <FormField id="name" label="Nome e cognome" :error="nameForm.errors.name">
                        <TextInput id="name" v-model="nameForm.name" autocomplete="name" :invalid="!!nameForm.errors.name" />
                    </FormField>
                    <PrimaryButton :disabled="nameForm.processing">Salva il nome</PrimaryButton>
                </form>
            </section>

            <section class="card p-4 sm:p-5 space-y-4" aria-labelledby="p-email">
                <h2 id="p-email" class="text-lg font-bold">Email di accesso</h2>
                <form class="space-y-4" novalidate @submit.prevent="saveEmail">
                    <FormField id="email" label="Email" :error="emailForm.errors.email">
                        <TextInput id="email" v-model="emailForm.email" type="email" autocomplete="email" :invalid="!!emailForm.errors.email" />
                    </FormField>
                    <FormField id="email-password" label="Per confermare, la tua password" :error="emailForm.errors.password">
                        <TextInput id="email-password" v-model="emailForm.password" type="password" autocomplete="current-password" :invalid="!!emailForm.errors.password" />
                    </FormField>
                    <PrimaryButton :disabled="emailForm.processing">Cambia email</PrimaryButton>
                </form>
            </section>

            <section class="card p-4 sm:p-5 space-y-4" aria-labelledby="p-pw">
                <h2 id="p-pw" class="text-lg font-bold">Password</h2>
                <form class="space-y-4" novalidate @submit.prevent="savePassword">
                    <FormField id="current_password" label="Password attuale" :error="passwordForm.errors.current_password">
                        <TextInput id="current_password" v-model="passwordForm.current_password" type="password" autocomplete="current-password" :invalid="!!passwordForm.errors.current_password" />
                    </FormField>
                    <FormField id="password" label="Nuova password" :error="passwordForm.errors.password">
                        <TextInput id="password" v-model="passwordForm.password" type="password" autocomplete="new-password" :invalid="!!passwordForm.errors.password" />
                    </FormField>
                    <FormField id="password_confirmation" label="Ripeti la nuova password">
                        <TextInput id="password_confirmation" v-model="passwordForm.password_confirmation" type="password" autocomplete="new-password" />
                    </FormField>
                    <PrimaryButton :disabled="passwordForm.processing">Cambia password</PrimaryButton>
                </form>
            </section>

            <section class="card p-4 sm:p-5 space-y-3" aria-labelledby="p-2fa">
                <h2 id="p-2fa" class="text-lg font-bold">Verifica in due passaggi</h2>
                <p class="text-muted">{{ props.twoFactor ? 'È attiva: a ogni accesso ti chiediamo un codice dall’app sul telefono.' : 'Non è ancora attiva.' }}</p>
                <Link href="/account/verifica-in-due-passaggi" class="btn btn-secondary">{{ props.twoFactor ? 'Gestisci' : 'Attivala' }}</Link>
            </section>

            <section class="card p-4 sm:p-5 space-y-4" aria-labelledby="p-dev">
                <h2 id="p-dev" class="text-lg font-bold">Dispositivi collegati</h2>
                <ul class="divide-y divide-line">
                    <li v-for="(s, i) in props.sessions" :key="i" class="py-2">
                        <p class="font-semibold">{{ s.device }}<span v-if="s.current" class="ml-2 text-sm text-ok-ink">questo dispositivo</span></p>
                        <p class="caption">{{ s.ip }} · ultimo uso {{ when(s.last) }}</p>
                    </li>
                </ul>
                <form v-if="props.sessions.length > 1" class="space-y-4" novalidate @submit.prevent="closeOthers">
                    <FormField id="others-password" label="Per scollegare gli altri, la tua password" :error="othersForm.errors.password">
                        <TextInput id="others-password" v-model="othersForm.password" type="password" autocomplete="current-password" :invalid="!!othersForm.errors.password" />
                    </FormField>
                    <PrimaryButton :disabled="othersForm.processing">Scollega gli altri dispositivi</PrimaryButton>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
