<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    person: Object, firstFreeCard: String, types: Array, levels: Array, accounts: Array,
    socialYear: Object, settings: Object, canEdit: Boolean, canSettings: Boolean,
});

const p = props.person ?? {};
const form = useForm({
    type: p.type ?? 'ordinary', first_name: p.first_name ?? '', last_name: p.last_name ?? '', email: p.email ?? '',
    phone: p.phone ?? '', card_number: p.card_number ?? '', tax_code: p.tax_code ?? '', birth_date: p.birth_date ?? '',
    birth_place: p.birth_place ?? '', address: p.address ?? '', iban: p.iban ?? '', notes: p.notes ?? '',
    level_id: p.level_id ?? '', host_person_id: '', joined_on: p.joined_on ?? '',
});
const isGuest = computed(() => form.type === 'guest');
const save = () => (props.person ? form.put(`/gestionale/soci/${props.person.id}`, { preserveScroll: true }) : form.post('/gestionale/soci'));

const today = new Date().toISOString().slice(0, 10);
const renew = useForm({ account_id: props.accounts[0]?.id ?? '', amount: p.fee ?? '', date: today, social_year: props.socialYear.current, method: '' });
const submitRenew = () => renew.post(`/gestionale/soci/${props.person.id}/tessera`, { preserveScroll: true });
const grant = useForm({ social_year: props.socialYear.current, reason: '' });
const submitGrant = () => grant.post(`/gestionale/soci/${props.person.id}/tessera-manuale`, { preserveScroll: true, onSuccess: () => grant.reset('reason') });
const revoke = (id) => router.post(`/gestionale/tessere/${id}/togli`, {}, { preserveScroll: true });
const leave = useForm({ date: today, reason: '' });
const submitLeave = () => leave.post(`/gestionale/soci/${props.person.id}/uscita`, { preserveScroll: true });
const settingsForm = useForm({ start_month: props.settings.start_month, fee: props.settings.fee });
const submitSettings = () => settingsForm.post('/gestionale/soci/impostazioni', { preserveScroll: true });
const months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink disabled:opacity-70';
const label = 'space-y-1 text-sm';
</script>

<template>
    <Head :title="person ? `${person.last_name} ${person.first_name}` : 'Nuova scheda'" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="font-semibold text-accent hover:underline">← Soci</Link></p>
        <div class="mb-6 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">{{ person ? `${person.first_name} ${person.last_name}` : 'Nuova scheda' }}</h1>
            <span v-if="person?.left_on" class="rounded bg-surface px-2 py-0.5 text-sm">Uscito il {{ person.left_on }}</span>
            <span v-else-if="person?.active" class="rounded bg-salvia/15 px-2 py-0.5 text-sm font-semibold">In regola</span>
            <span v-else-if="person" class="rounded bg-giallo/20 px-2 py-0.5 text-sm">Senza tessera valida</span>
            <span v-if="person?.minor" class="rounded bg-surface px-2 py-0.5 text-sm">Minorenne</span>
        </div>

        <form class="space-y-6 rounded-2xl border border-line bg-card p-5" @submit.prevent="save">
            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-2 text-lg font-bold">Dati principali</legend>
                <label :class="label"><span class="font-semibold">Tipo</span>
                    <select v-model="form.type" :class="field"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Nome</span><input v-model="form.first_name" required :class="field" /></label>
                <label :class="label"><span class="font-semibold">Cognome</span><input v-model="form.last_name" required :class="field" /></label>
                <label :class="label"><span class="font-semibold">Email</span><input v-model="form.email" type="email" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Cellulare</span><input v-model="form.phone" type="tel" :class="field" /></label>
                <label v-if="!isGuest" :class="label">
                    <span class="font-semibold">Tessera</span>
                    <span class="flex gap-2"><input v-model="form.card_number" :class="field" />
                        <button type="button" class="whitespace-nowrap text-sm font-semibold text-accent" @click="form.card_number = firstFreeCard">Primo libero ({{ firstFreeCard }})</button></span>
                </label>
                <div v-if="isGuest" class="space-y-1 text-sm sm:col-span-2">
                    <label for="host" class="font-semibold">Socio che lo ospita</label>
                    <PersonPicker id="host" v-model="form.host_person_id" />
                </div>
                <label v-if="levels.length && !isGuest" :class="label"><span class="font-semibold">Livello</span>
                    <select v-model="form.level_id" :class="field"><option value="">Nessuno</option><option v-for="l in levels" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">Data di ingresso</span><input v-model="form.joined_on" type="date" :class="field" /></label>
            </fieldset>

            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-1 text-lg font-bold">Dati riservati</legend>
                <p class="text-sm text-muted sm:col-span-3">Questi dati sono cifrati: nel database non si leggono.</p>
                <label :class="label"><span class="font-semibold">Codice fiscale</span><input v-model="form.tax_code" autocomplete="off" class="uppercase" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Data di nascita</span><input v-model="form.birth_date" type="date" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Luogo di nascita</span><input v-model="form.birth_place" :class="field" /></label>
                <label :class="label" class="sm:col-span-2"><span class="font-semibold">Indirizzo</span><input v-model="form.address" placeholder="Via, CAP, comune, provincia" :class="field" /></label>
                <label :class="label"><span class="font-semibold">IBAN</span><input v-model="form.iban" autocomplete="off" :class="field" /></label>
                <label :class="label" class="sm:col-span-3"><span class="font-semibold">Note</span><textarea v-model="form.notes" rows="3" :class="field" /></label>
            </fieldset>

            <ul v-if="Object.keys(form.errors).length" class="space-y-1 text-sm font-semibold text-danger" role="alert">
                <li v-for="(e, k) in form.errors" :key="k">{{ e }}</li>
            </ul>
            <button v-if="canEdit" type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="form.processing">{{ person ? 'Salva la scheda' : 'Crea la scheda' }}</button>
        </form>

        <template v-if="person && !isGuest && person.type !== 'guardian'">
            <section class="mt-6 rounded-2xl border border-line bg-card p-5">
                <h2 class="mb-3 text-lg font-bold">Tesseramento</h2>
                <p v-if="person.type === 'founder'" class="text-sm">Socio fondatore: la tessera è sempre rinnovata.</p>
                <table v-if="person.memberships.length" class="mb-4 w-full text-sm">
                    <thead class="text-left text-muted"><tr><th class="py-2">Anno sociale</th><th class="py-2">Valida fino al</th><th class="py-2">Origine</th><th class="py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="m in person.memberships" :key="m.id" class="border-t border-line" :class="m.valid ? '' : 'text-muted line-through'">
                            <td class="py-2">{{ m.social_year }}</td><td class="py-2">{{ m.valid_to }}</td>
                            <td class="py-2">{{ { payment: 'quota incassata', manual: 'senza incasso', import: 'importata', founder: 'fondatore' }[m.source] }}</td>
                            <td class="py-2 text-right"><button v-if="canEdit && m.valid && m.source !== 'payment'" type="button" class="font-semibold text-accent" @click="revoke(m.id)">Togli</button></td>
                        </tr>
                    </tbody>
                </table>
                <form v-if="canEdit && person.type !== 'founder' && !person.left_on" class="grid gap-3 sm:grid-cols-5" @submit.prevent="submitRenew">
                    <label :class="label"><span class="font-semibold">Anno sociale</span>
                        <select v-model="renew.social_year" :class="field"><option :value="socialYear.current">{{ socialYear.current }}</option><option :value="socialYear.next">{{ socialYear.next }}</option></select></label>
                    <label :class="label"><span class="font-semibold">Quota</span><input v-model="renew.amount" inputmode="decimal" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="renew.date" type="date" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Conto</span>
                        <select v-model="renew.account_id" :class="field"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    <div class="flex items-end"><button type="submit" class="w-full rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="renew.processing">Incassa e rinnova</button></div>
                    <p class="text-sm text-muted sm:col-span-5">L'incasso va in prima nota con la ricevuta; stornandolo la tessera non vale più.</p>
                </form>
                <form v-if="canEdit && person.type !== 'founder' && !person.left_on" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4" @submit.prevent="submitGrant">
                    <label :class="label" class="min-w-64 flex-1"><span class="font-semibold">Tessera senza incasso: motivo</span><input v-model="grant.reason" required placeholder="es. socio onorario" :class="field" /></label>
                    <button type="submit" class="rounded-lg border border-line px-4 py-2 font-semibold">Registra la tessera {{ grant.social_year }}</button>
                </form>
            </section>

            <section v-if="canEdit && !person.left_on" class="mt-6 rounded-2xl border border-line bg-card p-5">
                <h2 class="mb-3 text-lg font-bold">Uscita dall'associazione</h2>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitLeave">
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="leave.date" type="date" :class="field" required /></label>
                    <label :class="label" class="min-w-64 flex-1"><span class="font-semibold">Motivo (facoltativo)</span><input v-model="leave.reason" :class="field" /></label>
                    <button type="submit" class="rounded-lg border border-line px-4 py-2 font-semibold">Registra l'uscita</button>
                </form>
            </section>
        </template>

        <section v-if="canSettings && !person" class="mt-6 rounded-2xl border border-line bg-card p-5">
            <h2 class="mb-3 text-lg font-bold">Impostazioni del tesseramento</h2>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitSettings">
                <label :class="label"><span class="font-semibold">L'anno sociale inizia a</span>
                    <select v-model="settingsForm.start_month" :class="field"><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select></label>
                <label :class="label"><span class="font-semibold">Quota associativa</span><input v-model="settingsForm.fee" inputmode="decimal" :class="field" /></label>
                <button type="submit" class="rounded-lg border border-line px-4 py-2 font-semibold">Salva</button>
            </form>
        </section>
    </AppLayout>
</template>
