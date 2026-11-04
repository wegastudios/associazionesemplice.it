<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    activity: Object, kinds: Array, billings: Array, years: Array, accounts: Array, methods: Array, canEdit: Boolean, canAccounting: Boolean,
});

const a = props.activity ?? {};
const form = useForm({
    name: a.name ?? '', kind: a.kind ?? 'course', social_year: a.social_year ?? props.years[0], billing: a.billing ?? 'monthly',
    fee: a.fee ?? '', guest_fee: a.guest_fee ?? '', instructor_person_id: a.instructor_person_id ?? '', weekdays: a.weekdays ?? [],
    starts_on: a.starts_on ?? '', ends_on: a.ends_on ?? '', location: a.location ?? '', notes: a.notes ?? '',
});
const clean = (d) => ({ ...d, instructor_person_id: d.instructor_person_id || null, starts_on: d.starts_on || null, ends_on: d.ends_on || null });
const save = () => (props.activity
    ? form.transform(clean).put(`/gestionale/attivita/${props.activity.id}`, { preserveScroll: true })
    : form.transform(clean).post('/gestionale/attivita'));
const days = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];
const isCourse = computed(() => form.kind === 'course');

const today = new Date().toISOString().slice(0, 10);
const enroll = useForm({ person_id: '', start_month: '' });
const submitEnroll = () => enroll.transform((d) => ({ ...d, start_month: d.start_month || null }))
    .post(`/gestionale/attivita/${props.activity.id}/iscrizioni`, { preserveScroll: true, onSuccess: () => enroll.reset() });
const unenroll = (e) => router.post(`/gestionale/attivita/${props.activity.id}/iscrizioni/${e.person_id}/chiudi`, {}, { preserveScroll: true });

const paying = ref(null);
const pay = useForm({ person_id: '', account_id: props.accounts[0]?.id ?? '', date: today, months: [], amount: '', method: '' });
const startPay = (e) => {
    paying.value = e.person_id;
    pay.person_id = e.person_id;
    pay.months = props.activity.monthly ? e.unpaid.slice(0, 1) : [];
    pay.amount = '';
};
const submitPay = () => pay.transform((d) => ({ ...d, amount: d.amount || null, method: d.method || null }))
    .post(`/gestionale/attivita/${props.activity.id}/incassi`, { preserveScroll: true, onSuccess: () => { paying.value = null; } });

const spend = useForm({ account_id: props.accounts[0]?.id ?? '', date: today, amount: '', description: '', method: '', document_ref: '' });
const submitSpend = () => spend.transform((d) => ({ ...d, method: d.method || null }))
    .post(`/gestionale/attivita/${props.activity.id}/spese`, { preserveScroll: true, onSuccess: () => spend.reset('amount', 'description', 'document_ref') });
const archive = (archived) => router.post(`/gestionale/attivita/${props.activity.id}/archivia`, { archived }, { preserveScroll: true });
const monthLabel = (m) => props.activity.months.find((x) => x.value === m)?.label ?? m;

const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink disabled:opacity-70';
const label = 'space-y-1 text-sm';
</script>

<template>
    <Head :title="activity ? activity.name : 'Nuova attività'" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/attivita" class="text-accent hover:underline">← Attività</Link></p>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">{{ activity ? activity.name : 'Nuova attività' }}</h1>
            <span v-if="activity?.archived" class="rounded bg-surface px-2 py-0.5 text-sm">archiviata</span>
        </div>

        <section v-if="activity" class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="Bilancio dell'attività">
            <div class="rounded-2xl border border-line bg-card p-4"><p class="text-sm text-muted">Entrate</p><p class="text-xl font-bold tabular-nums">{{ activity.totals.income }}</p></div>
            <div class="rounded-2xl border border-line bg-card p-4"><p class="text-sm text-muted">Uscite</p><p class="text-xl font-bold tabular-nums">{{ activity.totals.expense }}</p></div>
            <div class="rounded-2xl border border-line bg-card p-4"><p class="text-sm text-muted">Risultato</p><p class="text-xl font-bold tabular-nums" :class="activity.totals.negative ? 'text-danger' : ''">{{ activity.totals.balance }}</p></div>
        </section>

        <form class="rounded-2xl border border-line bg-card p-5" @submit.prevent="save">
            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Nome</span><input v-model="form.name" :class="field" required maxlength="160" /></label>
                <label :class="label"><span class="font-semibold">Anno sociale</span><select v-model="form.social_year" :class="field"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></label>
                <label :class="label"><span class="font-semibold">Tipo</span><select v-model="form.kind" :class="field"><option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></label>
                <label v-if="isCourse" :class="label"><span class="font-semibold">Contributo</span><select v-model="form.billing" :class="field"><option v-for="b in billings" :key="b.value" :value="b.value">{{ b.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Importo per i soci</span><input v-model="form.fee" inputmode="decimal" placeholder="0 = gratuita" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Importo per gli ospiti</span><input v-model="form.guest_fee" inputmode="decimal" placeholder="vuoto = come i soci" :class="field" /></label>
                <label :class="label"><span class="font-semibold">{{ isCourse ? 'Inizio' : 'Data' }}</span><input v-model="form.starts_on" type="date" :class="field" :required="!isCourse" /></label>
                <label v-if="isCourse" :class="label"><span class="font-semibold">Fine</span><input v-model="form.ends_on" type="date" :class="field" /></label>
                <div v-if="isCourse" :class="[label, 'sm:col-span-2']">
                    <span class="font-semibold">Giorni delle lezioni</span>
                    <div class="flex flex-wrap gap-3 pt-1"><label v-for="(d, i) in days" :key="d" class="flex items-center gap-1"><input v-model="form.weekdays" type="checkbox" :value="i + 1" /> {{ d }}</label></div>
                    <span class="text-muted">Il mese è dovuto dalla prima lezione del mese.</span>
                </div>
                <label :class="label"><span class="font-semibold">Luogo</span><input v-model="form.location" :class="field" maxlength="190" /></label>
                <div :class="[label, 'sm:col-span-2']"><label for="instructor" class="font-semibold">Referente (socio volontario)</label>
                    <p v-if="activity?.instructor_name && form.instructor_person_id === activity.instructor_person_id" class="text-muted">{{ activity.instructor_name }} <button v-if="canEdit" type="button" class="ml-2 text-danger hover:underline" @click="form.instructor_person_id = ''">togli</button></p>
                    <PersonPicker v-else id="instructor" v-model="form.instructor_person_id" />
                </div>
                <label :class="[label, 'sm:col-span-3']"><span class="font-semibold">Note</span><textarea v-model="form.notes" rows="2" :class="field" maxlength="2000" /></label>
            </fieldset>
            <div v-if="canEdit" class="mt-4 flex flex-wrap gap-3">
                <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="form.processing">{{ activity ? 'Salva' : 'Crea l\'attività' }}</button>
                <button v-if="activity" type="button" class="rounded-lg border border-line px-4 py-2 font-semibold" @click="archive(!activity.archived)">{{ activity.archived ? 'Riapri' : 'Archivia' }}</button>
            </div>
        </form>

        <template v-if="activity">
            <section class="mt-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="enrolled-title">
                <h2 id="enrolled-title" class="mb-3 text-lg font-bold">Iscritti</h2>
                <p v-if="!activity.enrollments.length" class="mb-3 text-sm text-muted">Nessun iscritto.</p>
                <ul class="divide-y divide-line text-sm">
                    <li v-for="e in activity.enrollments" :key="e.person_id" class="py-3" :class="e.active ? '' : 'text-muted'">
                        <div class="flex flex-wrap items-center gap-3">
                            <Link :href="`/gestionale/soci/${e.person_id}`" class="font-semibold text-accent hover:underline">{{ e.name }}</Link>
                            <span class="text-muted">{{ e.type }} · dal {{ e.from }}<template v-if="e.to"> al {{ e.to }}</template></span>
                            <span :class="['rounded px-2 py-0.5', e.regular ? 'bg-salvia/15' : 'bg-danger/15 text-danger']">{{ e.regular ? 'in regola' : 'da versare ' + e.balance_label.replace('-', '') }}</span>
                            <span class="text-muted">dovuto {{ e.due }} · versato {{ e.paid }}</span>
                            <span class="ml-auto flex gap-3">
                                <button v-if="canEdit && canAccounting && !activity.archived" type="button" class="font-semibold text-accent hover:underline" @click="startPay(e)">Incassa</button>
                                <button v-if="canEdit && e.active" type="button" class="text-danger hover:underline" @click="unenroll(e)">Chiudi l'iscrizione</button>
                            </span>
                        </div>
                        <p v-if="activity.monthly && (e.unpaid.length || e.paid_months.length)" class="mt-1 text-xs text-muted">
                            <template v-if="e.paid_months.length">Pagati: {{ e.paid_months.map(monthLabel).join(', ') }}. </template>
                            <template v-if="e.unpaid.length">Da pagare: {{ e.unpaid.map(monthLabel).join(', ') }}.</template>
                        </p>
                        <form v-if="paying === e.person_id" class="mt-3 grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-4" @submit.prevent="submitPay">
                            <div v-if="activity.monthly" class="sm:col-span-4"><span class="font-semibold">Mesi</span>
                                <div class="flex flex-wrap gap-3 pt-1"><label v-for="m in activity.months" :key="m.value" class="flex items-center gap-1"><input v-model="pay.months" type="checkbox" :value="m.value" /> {{ m.label }}</label></div>
                            </div>
                            <label :class="label"><span class="font-semibold">{{ activity.monthly ? 'Importo per mese' : 'Importo' }}</span><input v-model="pay.amount" inputmode="decimal" :placeholder="activity.monthly ? e.fee : 'quanto manca'" :class="field" /></label>
                            <label :class="label"><span class="font-semibold">Data</span><input v-model="pay.date" type="date" :class="field" required /></label>
                            <label :class="label"><span class="font-semibold">Conto</span><select v-model="pay.account_id" :class="field"><option v-for="ac in accounts" :key="ac.id" :value="ac.id">{{ ac.name }}</option></select></label>
                            <label :class="label"><span class="font-semibold">Modalità</span><select v-model="pay.method" :class="field"><option value="">Quella del conto</option><option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                            <div class="flex gap-3 sm:col-span-4">
                                <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="pay.processing">Incassa con ricevuta</button>
                                <button type="button" class="rounded-lg border border-line px-4 py-2" @click="paying = null">Annulla</button>
                            </div>
                        </form>
                    </li>
                </ul>
                <form v-if="canEdit && !activity.archived" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitEnroll">
                    <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="enroll-person" class="font-semibold">Iscrivi una persona</label><PersonPicker id="enroll-person" v-model="enroll.person_id" /></div>
                    <label v-if="activity.monthly" class="space-y-1 text-sm"><span class="block font-semibold">Dal mese</span>
                        <select v-model="enroll.start_month" :class="field"><option value="">questo mese</option><option v-for="m in activity.months" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                    <button type="submit" class="rounded-lg border border-line px-4 py-2 font-semibold" :disabled="!enroll.person_id || enroll.processing">Iscrivi</button>
                </form>
            </section>

            <section v-if="canAccounting" class="mt-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="spend-title">
                <h2 id="spend-title" class="mb-3 text-lg font-bold">Registra una spesa dell'attività</h2>
                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitSpend">
                    <label :class="label"><span class="font-semibold">Importo</span><input v-model="spend.amount" inputmode="decimal" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="spend.date" type="date" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Conto</span><select v-model="spend.account_id" :class="field"><option v-for="ac in accounts" :key="ac.id" :value="ac.id">{{ ac.name }}</option></select></label>
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Descrizione</span><input v-model="spend.description" :class="field" placeholder="es. affitto palestra ottobre" /></label>
                    <label :class="label"><span class="font-semibold">Documento</span><input v-model="spend.document_ref" :class="field" placeholder="n. fattura o scontrino" /></label>
                    <div class="sm:col-span-3"><button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="spend.processing">Registra la spesa</button>
                        <span class="ml-3 text-sm text-muted">Va in prima nota come «Costo attività». Altri movimenti si collegano all'attività dalla prima nota.</span></div>
                </form>
            </section>

            <section class="mt-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="moves-title">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="moves-title" class="text-lg font-bold">Movimenti</h2>
                    <Link :href="`/gestionale/prima-nota?attivita=${activity.id}`" class="text-sm font-semibold text-accent hover:underline">Apri in prima nota</Link>
                </div>
                <p v-if="!activity.movements.length" class="text-sm text-muted">Nessun movimento collegato.</p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="m in activity.movements" :key="m.id" class="border-t border-line" :class="m.voided ? 'text-muted line-through' : ''">
                            <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ m.date }}</td>
                            <td class="py-2 pr-3">{{ m.description || m.category }}<span v-if="m.person" class="text-muted"> · {{ m.person }}</span><span v-if="m.receipt" class="ml-1 rounded bg-surface px-1.5 py-0.5 text-xs">ricevuta {{ m.receipt }}</span></td>
                            <td class="py-2 pr-3 text-muted">{{ m.category }}</td>
                            <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">{{ m.amount }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </AppLayout>
</template>
