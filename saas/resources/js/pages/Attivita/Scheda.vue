<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    activity: Object, resources: Array, kinds: Array, billings: Array, years: Array, accounts: Array, methods: Array, canEdit: Boolean, canAccounting: Boolean,
});

const a = props.activity ?? {};
const today = new Date().toISOString().slice(0, 10);
const form = useForm({
    name: a.name ?? '', kind: a.kind ?? 'course', social_year: a.social_year ?? props.years[0], billing: a.billing ?? 'monthly',
    fee: a.fee ?? '', guest_fee: a.guest_fee ?? '', instructor_person_id: a.instructor_person_id ?? '', weekdays: a.weekdays ?? [],
    starts_on: a.starts_on ?? '', ends_on: a.ends_on ?? '', location: a.location ?? '', notes: a.notes ?? '', price_lists: a.price_lists ?? '',
    resource: a.resource ?? '', occupy_on_booking: a.occupy_on_booking ?? false, move_conflicts: false,
});
// Cambio di risorsa con sovrapposizioni: o si lascia tutto com'è, o si spostano gli slot occupati sulla risorsa che si libera.
const keepResource = () => { form.resource = a.resource ?? ''; form.clearErrors(); };
const saveMoving = () => { form.move_conflicts = true; save(); form.move_conflicts = false; };
const clean = (d) => ({ ...d, instructor_person_id: d.instructor_person_id || null, starts_on: d.starts_on || null, ends_on: d.ends_on || null });
const save = () => (props.activity
    ? form.transform(clean).put(`/gestionale/attivita/${props.activity.id}`, { preserveScroll: true })
    : form.transform(clean).post('/gestionale/attivita'));
const days = ['lun', 'mar', 'mer', 'gio', 'ven', 'sab', 'dom'];
const isCourse = computed(() => form.kind === 'course');
const isBookable = computed(() => form.kind === 'recurring');

const dates = useForm({ from: today, to: '', weekly: false, start: '', end: '', location: a.location ?? '', capacity: '' });
const submitDates = () => dates.transform((d) => ({ ...d, to: d.weekly ? d.to || null : null, capacity: d.capacity || null, start: d.start || null, end: d.end || null }))
    .post(`/gestionale/attivita/${props.activity.id}/date`, { preserveScroll: true, onSuccess: () => dates.reset('to', 'weekly') });

const enroll = useForm({ person_id: '', start_month: '' });
const submitEnroll = () => enroll.transform((d) => ({ ...d, start_month: d.start_month || null }))
    .post(`/gestionale/attivita/${props.activity.id}/iscrizioni`, { preserveScroll: true, onSuccess: () => enroll.reset() });
const unenroll = (e) => window.confirm(`Chiudere l'iscrizione di ${e.name ?? 'questa persona'}?`) && router.post(`/gestionale/attivita/${props.activity.id}/iscrizioni/${e.person_id}/chiudi`, {}, { preserveScroll: true });

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

const field = 'field';
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
            <div class="card p-4"><p class="text-sm text-muted">Entrate</p><p class="text-xl font-bold tabular-nums">{{ activity.totals.income }}</p></div>
            <div class="card p-4"><p class="text-sm text-muted">Uscite</p><p class="text-xl font-bold tabular-nums">{{ activity.totals.expense }}</p></div>
            <div class="card p-4"><p class="text-sm text-muted">Risultato</p><p class="text-xl font-bold tabular-nums" :class="activity.totals.negative ? 'text-danger' : ''">{{ activity.totals.balance }}</p></div>
        </section>

        <form class="card p-4 sm:p-5" @submit.prevent="save">
            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Nome</span><input v-model="form.name" :class="field" required maxlength="160" /></label>
                <label :class="label"><span class="font-semibold">Anno sociale</span><select v-model="form.social_year" :class="field"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></label>
                <label :class="label"><span class="font-semibold">Tipo</span><select v-model="form.kind" :class="field"><option v-for="k in kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></label>
                <label v-if="isCourse" :class="label"><span class="font-semibold">Contributo</span><select v-model="form.billing" :class="field"><option v-for="b in billings" :key="b.value" :value="b.value">{{ b.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Importo per i soci</span><input v-model="form.fee" inputmode="decimal" placeholder="0 = gratuita" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Importo per gli ospiti</span><input v-model="form.guest_fee" inputmode="decimal" placeholder="vuoto = come i soci" :class="field" /></label>
                <label v-if="!isBookable" :class="label"><span class="font-semibold">{{ isCourse ? 'Inizio' : 'Data' }}</span><input v-model="form.starts_on" type="date" :class="field" :required="!isCourse" /></label>
                <label v-if="isCourse" :class="label"><span class="font-semibold">Fine</span><input v-model="form.ends_on" type="date" :class="field" /></label>
                <div v-if="isCourse" :class="[label, 'sm:col-span-2']">
                    <span class="font-semibold">Giorni delle lezioni</span>
                    <div class="flex flex-wrap gap-3 pt-1"><label v-for="(d, i) in days" :key="d" class="flex items-center gap-1"><input v-model="form.weekdays" type="checkbox" :value="i + 1" /> {{ d }}</label></div>
                    <span class="text-muted">Il mese è dovuto dalla prima lezione del mese.</span>
                </div>
                <label :class="label"><span class="font-semibold">Luogo</span><input v-model="form.location" :class="field" maxlength="190" /></label>
                <div :class="[label, 'sm:col-span-2']">
                    <label class="flex flex-col gap-1"><span class="font-semibold">Dove si svolge (risorsa)</span>
                        <select v-model="form.resource" :class="field" required>
                            <option value="" disabled>Scegli…</option>
                            <option value="none">Nessuna (fuori sede)</option>
                            <option v-for="r in resources" :key="r.id" :value="r.id">{{ r.name }}</option>
                        </select></label>
                    <span class="text-muted">Serve a sapere quando la risorsa è libera: più attività sulla stessa risorsa ne condividono il calendario.</span>
                    <span v-if="form.errors.resource" class="text-danger">{{ form.errors.resource }}</span>
                    <div v-if="form.errors.resource && form.errors.resource_movable !== undefined" class="flex flex-wrap gap-3 pt-1">
                        <button type="button" class="btn" @click="keepResource">Lascia tutto invariato</button>
                        <button v-if="form.errors.resource_movable === '1'" type="button" class="btn btn-primary" @click="saveMoving">Sposta gli slot occupati sulla risorsa che si libera</button>
                        <span v-if="form.errors.resource_movable === '1'" class="text-muted basis-full">Si spostano solo le prenotazioni e le attività future, non il passato; i blocchi della risorsa non si spostano. Una attività in conflitto passa per intero sulla risorsa di prima.</span>
                        <span v-else class="text-muted basis-full">Con un blocco della risorsa in mezzo non si può spostare: togli il blocco o lascia tutto invariato.</span>
                    </div>
                </div>
                <label :class="label"><span class="flex items-center gap-2"><input v-model="form.occupy_on_booking" type="checkbox" /> <span class="font-semibold">Occupa la risorsa solo se prenotata</span></span>
                    <span class="text-muted">Le date senza prenotazioni lasciano libera la risorsa; una prenotazione su una fascia occupata altrove viene rifiutata.</span></label>
                <div :class="[label, 'sm:col-span-2']"><label for="instructor" class="font-semibold">Referente (socio volontario)</label>
                    <p v-if="activity?.instructor_name && form.instructor_person_id === activity.instructor_person_id" class="text-muted">{{ activity.instructor_name }} <button v-if="canEdit" type="button" class="ml-2 text-danger hover:underline" @click="form.instructor_person_id = ''">togli</button></p>
                    <PersonPicker v-else id="instructor" v-model="form.instructor_person_id" />
                </div>
                <label v-if="isBookable" :class="[label, 'sm:col-span-3']"><span class="font-semibold">Listini</span>
                    <textarea v-model="form.price_lists" rows="2" :class="field" placeholder="Ingresso singolo 10,00&#10;Pacchetto 5 ingressi 40 x5" />
                    <span class="text-muted">Facoltativi, uno per riga: nome e prezzo; per un pacchetto «x» e gli ingressi. Senza listino vale l'importo per i soci o per gli ospiti.</span>
                    <span v-if="form.errors.price_lists" class="text-danger">{{ form.errors.price_lists }}</span></label>
                <label :class="[label, 'sm:col-span-3']"><span class="font-semibold">Note</span><textarea v-model="form.notes" rows="2" :class="field" maxlength="2000" /></label>
            </fieldset>
            <div v-if="canEdit" class="mt-4 flex flex-wrap gap-3">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ activity ? 'Salva' : 'Crea l\'attività' }}</button>
                <button v-if="activity" type="button" class="btn btn-secondary" @click="archive(!activity.archived)">{{ activity.archived ? 'Riapri' : 'Archivia' }}</button>
            </div>
        </form>

        <p v-if="activity" class="mt-4 flex flex-wrap gap-4 text-sm">
            <Link :href="`/gestionale/attivita/${activity.id}/presenze`" class="font-semibold text-accent hover:underline">Registro presenze</Link>
            <Link href="/gestionale/calendario" class="font-semibold text-accent hover:underline">Calendario</Link>
        </p>

        <section v-if="activity && activity.bookable && activity.packages.length" class="mt-6 card p-4 sm:p-5" aria-labelledby="packs-title">
            <h2 id="packs-title" class="mb-1 text-lg font-bold">Pacchetti con ingressi residui</h2>
            <p class="mb-3 text-sm text-muted">Pacchetti già pagati con ingressi ancora da usare. Per scalarne uno, prenota la persona a una data scegliendo «Un ingresso da un pacchetto già preso».</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="p in activity.packages" :key="p.id" class="flex flex-wrap items-center gap-3 py-2">
                    <Link :href="`/gestionale/soci/${p.person_id}`" class="font-semibold text-accent hover:underline">{{ p.name }}</Link>
                    <span class="text-muted">{{ p.label }}</span>
                    <span class="rounded bg-surface px-2 py-0.5">{{ p.left }} {{ p.left === 1 ? 'ingresso rimasto' : 'ingressi rimasti' }} su {{ p.entries }}</span>
                </li>
            </ul>
        </section>

        <section v-if="activity && activity.bookable" class="mt-6 card p-4 sm:p-5" aria-labelledby="dates-title">
            <h2 id="dates-title" class="mb-3 text-lg font-bold">Date</h2>
            <p v-if="!activity.sessions.length" class="mb-3 text-sm text-muted">Nessuna data: aggiungi la prima.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="d in activity.sessions" :key="d.id" class="flex flex-wrap items-center gap-3 py-2" :class="d.cancelled ? 'text-muted line-through' : ''">
                    <Link :href="`/gestionale/attivita/date/${d.id}`" class="font-semibold text-accent hover:underline">{{ d.label }}<template v-if="d.start"> · {{ d.start }}<template v-if="d.end">–{{ d.end }}</template></template></Link>
                    <span v-if="d.location" class="text-muted">{{ d.location }}</span>
                    <span v-if="!d.cancelled" :class="['rounded px-2 py-0.5', d.free === 0 ? 'bg-danger/15 text-danger' : 'bg-surface']">{{ d.taken }} prenotati<template v-if="d.capacity"> su {{ d.capacity }}</template></span>
                    <span v-if="d.waiting" class="text-muted">{{ d.waiting }} in attesa</span>
                    <span v-if="d.cancelled">annullata</span>
                </li>
            </ul>
            <form v-if="canEdit && !activity.archived" class="mt-4 grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-4" @submit.prevent="submitDates">
                <label :class="label"><span class="font-semibold">Data</span><input v-model="dates.from" type="date" :class="field" required /></label>
                <label class="flex items-center gap-2 self-end pb-2 text-sm"><input v-model="dates.weekly" type="checkbox" /> ogni settimana</label>
                <label v-if="dates.weekly" :class="label"><span class="font-semibold">Fino al</span><input v-model="dates.to" type="date" :class="field" required /></label>
                <label :class="label"><span class="font-semibold">Dalle</span><input v-model="dates.start" type="time" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Alle</span><input v-model="dates.end" type="time" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Posti</span><input v-model="dates.capacity" type="number" min="1" :class="field" placeholder="senza limite" /></label>
                <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Luogo</span><input v-model="dates.location" :class="field" maxlength="190" /></label>
                <div class="sm:col-span-4"><button type="submit" class="btn btn-primary" :disabled="dates.processing">Aggiungi {{ dates.weekly ? 'le date' : 'la data' }}</button></div>
            </form>
        </section>

        <template v-if="activity">
            <section v-if="!activity.bookable" class="mt-6 card p-4 sm:p-5" aria-labelledby="enrolled-title">
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
                                <button type="submit" class="btn btn-primary" :disabled="pay.processing">Incassa con ricevuta</button>
                                <button type="button" class="btn btn-secondary" @click="paying = null">Annulla</button>
                            </div>
                        </form>
                    </li>
                </ul>
                <form v-if="canEdit && !activity.archived" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitEnroll">
                    <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="enroll-person" class="font-semibold">Iscrivi una persona</label><PersonPicker id="enroll-person" v-model="enroll.person_id" /></div>
                    <label v-if="activity.monthly" class="space-y-1 text-sm"><span class="block font-semibold">Dal mese</span>
                        <select v-model="enroll.start_month" :class="field"><option value="">questo mese</option><option v-for="m in activity.months" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                    <button type="submit" class="btn btn-secondary" :disabled="!enroll.person_id || enroll.processing">Iscrivi</button>
                </form>
            </section>

            <section v-if="canAccounting" class="mt-6 card p-4 sm:p-5" aria-labelledby="spend-title">
                <h2 id="spend-title" class="mb-3 text-lg font-bold">Registra una spesa dell'attività</h2>
                <form class="grid gap-3 sm:grid-cols-3" @submit.prevent="submitSpend">
                    <label :class="label"><span class="font-semibold">Importo</span><input v-model="spend.amount" inputmode="decimal" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="spend.date" type="date" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Conto</span><select v-model="spend.account_id" :class="field"><option v-for="ac in accounts" :key="ac.id" :value="ac.id">{{ ac.name }}</option></select></label>
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Descrizione</span><input v-model="spend.description" :class="field" placeholder="es. affitto palestra ottobre" /></label>
                    <label :class="label"><span class="font-semibold">Documento</span><input v-model="spend.document_ref" :class="field" placeholder="n. fattura o scontrino" /></label>
                    <div class="sm:col-span-3"><button type="submit" class="btn btn-primary" :disabled="spend.processing">Registra la spesa</button>
                        <span class="ml-3 text-sm text-muted">Va in prima nota come «Costo attività». Altri movimenti si collegano all'attività dalla prima nota.</span></div>
                </form>
            </section>

            <section class="mt-6 card p-4 sm:p-5" aria-labelledby="moves-title">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="moves-title" class="text-lg font-bold">Movimenti</h2>
                    <Link :href="`/gestionale/prima-nota?attivita=${activity.id}`" class="text-sm font-semibold text-accent hover:underline">Apri in prima nota</Link>
                </div>
                <p v-if="!activity.movements.length" class="text-sm text-muted">Nessun movimento collegato.</p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="m in activity.movements" :key="m.id" class="border-t border-line" :class="m.voided ? 'text-muted line-through' : ''">
                            <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ m.date }}</td>
                            <td class="py-2 pr-3">{{ m.description || m.category }}<span v-if="m.person" class="text-muted"> · {{ m.person }}</span><a v-if="m.receipt && !m.voided" :href="`/gestionale/ricevute/${m.receipt_id}.pdf`" target="_blank" class="ml-1 rounded bg-surface px-1.5 py-0.5 text-xs font-semibold text-accent hover:underline">ricevuta {{ m.receipt }}</a><span v-else-if="m.receipt" class="ml-1 rounded bg-surface px-1.5 py-0.5 text-xs">ricevuta {{ m.receipt }}</span></td>
                            <td class="py-2 pr-3 text-muted">{{ m.category }}</td>
                            <td class="whitespace-nowrap py-2 text-right font-semibold tabular-nums">{{ m.amount }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </AppLayout>
</template>
