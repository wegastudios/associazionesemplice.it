<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ pending: Array, decided: Array, firstFreeCard: String, formUrl: String, settings: Object, canEdit: Boolean, canSettings: Boolean });

const settingsForm = useForm({ ...props.settings });
const saveSettings = () => settingsForm.post('/gestionale/domande/impostazioni', { preserveScroll: true });
const status = { approved: 'accolta', rejected: 'respinta' };
const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Domande di adesione" />
    <AppLayout>
        <h1 class="mb-4 text-2xl font-bold">Domande di adesione</h1>

        <section class="rounded-2xl border border-line bg-card p-5" aria-labelledby="pending-title">
            <h2 id="pending-title" class="mb-3 text-lg font-bold">Da esaminare</h2>
            <p v-if="!pending.length" class="text-sm text-muted">Nessuna domanda in attesa.</p>
            <ul v-else class="divide-y divide-line text-sm">
                <li v-for="a in pending" :key="a.id" class="flex flex-wrap items-center gap-3 py-2">
                    <Link :href="`/gestionale/domande/${a.id}`" class="font-semibold text-accent hover:underline">{{ a.name }}</Link>
                    <span v-if="a.minor" class="rounded bg-giallo/25 px-2 py-0.5">minore</span>
                    <span class="ml-auto text-muted">arrivata il {{ a.submitted }}</span>
                </li>
            </ul>
        </section>

        <section v-if="decided.length" class="mt-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="decided-title">
            <h2 id="decided-title" class="mb-3 text-lg font-bold">Decise di recente</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="a in decided" :key="a.id" class="flex flex-wrap items-center gap-3 py-2">
                    <Link v-if="a.person_id" :href="`/gestionale/soci/${a.person_id}`" class="font-semibold text-accent hover:underline">{{ a.name }}</Link>
                    <span v-else class="font-semibold">{{ a.name }}</span>
                    <span :class="a.status === 'approved' ? 'rounded bg-salvia/15 px-2 py-0.5' : 'rounded bg-surface px-2 py-0.5'">{{ status[a.status] }}</span>
                    <span v-if="a.reason" class="text-muted">{{ a.reason }}</span>
                    <span class="ml-auto text-muted">{{ a.decided }}</span>
                </li>
            </ul>
        </section>

        <section v-if="canSettings" class="mt-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="settings-title">
            <h2 id="settings-title" class="mb-3 text-lg font-bold">Domande online e promemoria</h2>
            <form class="grid gap-4 text-sm sm:grid-cols-2" @submit.prevent="saveSettings">
                <label class="flex items-center gap-2 sm:col-span-2"><input v-model="settingsForm.applications_enabled" type="checkbox" /> Accetta domande di adesione online</label>
                <p v-if="formUrl && settingsForm.applications_enabled" class="sm:col-span-2 text-muted">Indirizzo del modulo da pubblicare sul sito: <a :href="formUrl" class="font-semibold text-accent hover:underline" target="_blank" rel="noopener">{{ formUrl }}</a></p>
                <label class="flex items-center gap-2 sm:col-span-2"><input v-model="settingsForm.applications_rules" type="checkbox" /> Chiedi di accettare il regolamento</label>
                <label class="space-y-1"><span class="block font-semibold">Indirizzo dell'informativa privacy</span><input v-model="settingsForm.privacy_url" type="url" placeholder="https://" :class="field" /><span v-if="settingsForm.errors.privacy_url" class="text-danger">{{ settingsForm.errors.privacy_url }}</span></label>
                <label class="space-y-1"><span class="block font-semibold">Indirizzo del regolamento</span><input v-model="settingsForm.rules_url" type="url" placeholder="https://" :class="field" /><span v-if="settingsForm.errors.rules_url" class="text-danger">{{ settingsForm.errors.rules_url }}</span></label>
                <label class="flex items-center gap-2 sm:col-span-2"><input v-model="settingsForm.reminders_enabled" type="checkbox" /> Manda per email i promemoria di tessera e certificato medico in scadenza</label>
                <label class="space-y-1"><span class="block font-semibold">Giorni di preavviso per la tessera</span><input v-model.number="settingsForm.reminders_days" type="number" min="1" max="365" :class="field" /></label>
                <div class="sm:col-span-2"><button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-white" :disabled="settingsForm.processing">Salva</button></div>
            </form>
        </section>
    </AppLayout>
</template>
