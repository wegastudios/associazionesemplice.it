<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    resource: Object, date: String, grid: Array, categories: Array, typologies: Array, incomeCategories: Array, accounts: Array, methods: Array,
    today: String, canEdit: Boolean, canAccounting: Boolean,
});

const r = props.resource ?? {};
const form = useForm({
    name: r.name ?? '', category: r.category ?? 'court', typology: r.typology ?? 'hourly', description: r.description ?? '',
    open_time: r.open_time ?? '08:00', close_time: r.close_time ?? '22:00', slot_minutes: r.slot_minutes ?? 60, min_slots: r.min_slots ?? 1,
    max_slots: r.max_slots ?? 2, advance_days: r.advance_days ?? 14, fee: r.fee ?? '', nonmember_fee: r.nonmember_fee ?? '',
    guests_max: r.guests_max ?? 0, guest_fee: r.guest_fee ?? '', rates: r.rates ?? '', services: r.services ?? '', category_id: r.category_id ?? '',
});
const clean = (d) => ({ ...d, category_id: d.category_id || null });
const save = () => (props.resource
    ? form.transform(clean).put(`/gestionale/risorse/${props.resource.id}`, { preserveScroll: true })
    : form.transform(clean).post('/gestionale/risorse'));
const archive = (archived) => router.post(`/gestionale/risorse/${props.resource.id}/archivia`, { archived }, { preserveScroll: true });
const hourly = computed(() => form.typology === 'hourly');
const seasonal = computed(() => form.typology === 'seasonal');
const unitWord = computed(() => ({ hourly: 'a fascia', daily: 'al giorno', seasonal: 'per la stagione' })[form.typology]);

// Prenotazione
const kindOf = computed(() => props.resource?.typology);
const who = ref('person');
const book = useForm({ person_id: '', holder_name: '', date: props.date, start: '', units: 1, weeks: 1, to: '', guests: '', services: [], note: '' });
const quote = ref('');
const quoteError = ref('');
let timer = null;
const payload = (d) => ({
    ...d, person_id: who.value === 'person' ? d.person_id || null : null, holder_name: who.value === 'external' ? d.holder_name || null : null,
    start: kindOf.value === 'hourly' ? d.start || null : null, to: d.to || null, weeks: kindOf.value === 'hourly' ? d.weeks : 1,
});
watch(() => [book.person_id, book.holder_name, book.date, book.start, book.units, book.to, book.guests, book.services.join('|'), who.value], () => {
    clearTimeout(timer);
    quote.value = '';
    quoteError.value = '';
    if (!props.resource || !book.date || (kindOf.value === 'hourly' && !book.start)) return;
    timer = setTimeout(async () => {
        const token = decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
        const res = await fetch(`/gestionale/risorse/${props.resource.id}/preventivo`, {
            method: 'POST', credentials: 'same-origin',
            headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-XSRF-TOKEN': token },
            body: JSON.stringify(payload(book.data())),
        });
        const json = await res.json().catch(() => ({}));
        if (res.ok) quote.value = json.fee;
        else quoteError.value = json.error ?? '';
    }, 300);
});
const submitBook = () => book.transform(payload).post(`/gestionale/risorse/${props.resource.id}/prenotazioni`, {
    preserveScroll: true, onSuccess: () => book.reset('person_id', 'holder_name', 'start', 'guests', 'services', 'note', 'weeks'),
});
const pickSlot = (s) => {
    if (s.busy || !props.canEdit) return;
    book.start = s.start;
    book.date = props.date;
    document.getElementById('book-title')?.scrollIntoView({ behavior: 'smooth' });
};
const goDay = (d) => router.get(`/gestionale/risorse/${props.resource.id}`, { giorno: d }, { preserveScroll: true, preserveState: false });
const shift = (n) => {
    const d = new Date(`${props.date}T12:00:00`);
    d.setDate(d.getDate() + n);
    goDay(d.toISOString().slice(0, 10));
};

// Blocco, annullamento, incasso, lista d'attesa
const block = useForm({ from: `${props.date}T08:00`, to: `${props.date}T12:00`, note: '' });
const submitBlock = () => block.post(`/gestionale/risorse/${props.resource.id}/blocchi`, { preserveScroll: true, onSuccess: () => block.reset('note') });
const cancel = (b, series = false) => {
    if (confirm(series ? 'Annullare questa prenotazione e le successive della serie?' : 'Annullare questa prenotazione?')) {
        router.post(`/gestionale/prenotazioni/${b.id}/annulla`, { series }, { preserveScroll: true });
    }
};
const paying = ref(null);
const pay = useForm({ account_id: props.accounts[0]?.id ?? '', date: props.today, amount: '', method: '' });
const startPay = (b) => {
    paying.value = b.id;
    pay.amount = '';
};
const submitPay = () => pay.transform((d) => ({ ...d, amount: d.amount || null, method: d.method || null }))
    .post(`/gestionale/prenotazioni/${paying.value}/incassa`, { preserveScroll: true, onSuccess: () => { paying.value = null; } });
const wait = useForm({ person_id: '', note: '' });
const submitWait = () => wait.post(`/gestionale/risorse/${props.resource.id}/attesa`, { preserveScroll: true, onSuccess: () => wait.reset() });
const leave = (w) => router.post(`/gestionale/risorse/${props.resource.id}/attesa/${w.person_id}/esci`, {}, { preserveScroll: true });

const field = 'field';
const label = 'space-y-1 text-sm';
</script>

<template>
    <Head :title="resource ? resource.name : 'Nuova risorsa'" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/risorse" class="text-accent hover:underline">← Risorse</Link></p>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">{{ resource ? resource.name : 'Nuova risorsa' }}</h1>
            <span v-if="resource?.archived" class="rounded bg-surface px-2 py-0.5 text-sm">archiviata</span>
        </div>

        <template v-if="resource && !resource.archived">
            <section v-if="resource.typology === 'hourly'" class="mb-6 card p-4 sm:p-5" aria-labelledby="grid-title">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 id="grid-title" class="text-lg font-bold">Fasce del giorno</h2>
                    <div class="flex items-center gap-2 text-sm">
                        <button type="button" class="field w-auto" aria-label="Giorno prima" @click="shift(-1)">←</button>
                        <input type="date" :value="date" class="field w-auto" aria-label="Giorno" @change="goDay($event.target.value)" />
                        <button type="button" class="field w-auto" aria-label="Giorno dopo" @click="shift(1)">→</button>
                    </div>
                </div>
                <div class="grid gap-2 sm:grid-cols-3 lg:grid-cols-4">
                    <button v-for="s in grid" :key="s.start" type="button" :disabled="s.busy || !canEdit"
                        :class="['rounded-lg border px-3 py-2 text-left text-sm', s.busy ? 'border-line bg-surface text-muted' : 'border-salvia/40 bg-salvia/10 hover:border-accent', book.start === s.start && !s.busy ? 'ring-2 ring-accent' : '']"
                        @click="pickSlot(s)">
                        <span class="font-semibold tabular-nums">{{ s.start }}–{{ s.end }}</span>
                        <span class="block truncate">{{ s.label }}<template v-if="!s.busy"> · {{ s.price }}</template></span>
                    </button>
                </div>
            </section>

            <section v-if="canEdit" class="mb-6 card p-4 sm:p-5" aria-labelledby="book-title">
                <h2 id="book-title" class="mb-3 text-lg font-bold">{{ resource.typology === 'seasonal' ? 'Assegna per una stagione' : 'Nuova prenotazione' }}</h2>
                <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="submitBook">
                    <div class="flex flex-wrap gap-4 text-sm sm:col-span-4">
                        <label class="flex items-center gap-1"><input v-model="who" type="radio" value="person" /> Persona in anagrafica</label>
                        <label v-if="resource.typology !== 'seasonal'" class="flex items-center gap-1"><input v-model="who" type="radio" value="external" /> Esterno</label>
                    </div>
                    <div v-if="who === 'person'" :class="[label, 'sm:col-span-2']"><label for="book-person" class="font-semibold">Chi prenota</label><PersonPicker id="book-person" v-model="book.person_id" /></div>
                    <label v-else :class="[label, 'sm:col-span-2']"><span class="font-semibold">Nome di chi prenota</span><input v-model="book.holder_name" :class="field" maxlength="160" placeholder="es. Torneo, azienda, privato" /></label>
                    <label :class="label"><span class="font-semibold">{{ resource.typology === 'seasonal' ? 'Dal' : 'Giorno' }}</span><input v-model="book.date" type="date" :class="field" required /></label>
                    <label v-if="resource.typology === 'seasonal'" :class="label"><span class="font-semibold">Al (compreso)</span><input v-model="book.to" type="date" :class="field" placeholder="senza scadenza" /></label>
                    <label v-if="resource.typology === 'hourly'" :class="label"><span class="font-semibold">Dalle</span>
                        <select v-model="book.start" :class="field" required><option value="" disabled>scegli</option><option v-for="s in grid" :key="s.start" :value="s.start" :disabled="s.busy">{{ s.start }}</option></select></label>
                    <label v-if="resource.typology === 'hourly'" :class="label"><span class="font-semibold">Fasce</span>
                        <select v-model="book.units" :class="field"><option v-for="n in resource.max_slots" :key="n" :value="n" :disabled="n < resource.min_slots">{{ n }}</option></select></label>
                    <label v-if="resource.typology === 'daily'" :class="label"><span class="font-semibold">Giorni</span><input v-model.number="book.units" type="number" min="1" max="31" :class="field" /></label>
                    <label v-if="resource.typology === 'hourly'" :class="label"><span class="font-semibold">Ogni settimana per</span>
                        <select v-model="book.weeks" :class="field"><option :value="1">solo questa volta</option><option v-for="n in [2, 4, 8, 12, 16, 26, 52]" :key="n" :value="n">{{ n }} settimane</option></select></label>
                    <label v-if="resource.guests_max > 0" :class="[label, 'sm:col-span-2']"><span class="font-semibold">Ospiti (massimo {{ resource.guests_max }})</span><input v-model="book.guests" :class="field" placeholder="nomi separati da virgola" /></label>
                    <div v-if="resource.service_list.length" :class="[label, 'sm:col-span-2']"><span class="font-semibold">Servizi aggiuntivi</span>
                        <div class="flex flex-wrap gap-3 pt-1"><label v-for="s in resource.service_list" :key="s.name" class="flex items-center gap-1"><input v-model="book.services" type="checkbox" :value="s.name" /> {{ s.label }}</label></div>
                    </div>
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Note</span><input v-model="book.note" :class="field" maxlength="255" /></label>
                    <div class="flex flex-wrap items-center gap-3 sm:col-span-4">
                        <button type="submit" class="btn btn-primary" :disabled="book.processing">{{ resource.typology === 'seasonal' ? 'Assegna' : 'Prenota' }}</button>
                        <span v-if="quote" class="text-sm">Dovuto: <strong class="tabular-nums">{{ quote }}</strong><template v-if="book.weeks > 1"> a settimana</template></span>
                        <span v-else-if="quoteError" class="text-sm text-danger">{{ quoteError }}</span>
                        <span class="text-sm text-muted">Chi ha la tessera valida paga la tariffa soci.</span>
                    </div>
                </form>
            </section>
        </template>

        <section v-if="resource" class="mb-6 card p-4 sm:p-5" aria-labelledby="next-title">
            <h2 id="next-title" class="mb-3 text-lg font-bold">{{ resource.typology === 'seasonal' ? 'Assegnazioni' : 'Prossime prenotazioni' }}</h2>
            <p v-if="!resource.upcoming.length" class="text-sm text-muted">Nessuna.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="b in resource.upcoming" :key="b.id" class="py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-semibold tabular-nums">{{ b.when }}</span>
                        <template v-if="b.block"><span class="rounded bg-surface px-2 py-0.5">bloccata</span><span class="text-muted">{{ b.note }}</span></template>
                        <template v-else>
                            <span>{{ b.who }}</span>
                            <span v-if="!b.member" class="rounded bg-surface px-2 py-0.5 text-xs">non socio</span>
                            <span v-if="b.series" class="rounded bg-surface px-2 py-0.5 text-xs">serie</span>
                            <span class="text-muted">dovuto {{ b.fee }} · pagato {{ b.paid }}</span>
                            <span v-if="b.due > 0" class="rounded bg-danger/15 px-2 py-0.5 text-danger">da incassare {{ b.due_label }}</span>
                        </template>
                        <span class="ml-auto flex gap-3">
                            <button v-if="canEdit && canAccounting && !b.block && b.due > 0" type="button" class="font-semibold text-accent hover:underline" @click="startPay(b)">Incassa</button>
                            <button v-if="canEdit" type="button" class="text-danger hover:underline" @click="cancel(b)">Annulla</button>
                            <button v-if="canEdit && b.series" type="button" class="text-danger hover:underline" @click="cancel(b, true)">Annulla le successive</button>
                        </span>
                    </div>
                    <p v-if="b.guests || b.services || (b.note && !b.block)" class="mt-1 text-xs text-muted">
                        <template v-if="b.guests">Ospiti: {{ b.guests }}. </template><template v-if="b.services">Servizi: {{ b.services }}. </template><template v-if="b.note && !b.block">{{ b.note }}</template>
                    </p>
                    <form v-if="paying === b.id" class="mt-3 grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-4" @submit.prevent="submitPay">
                        <label :class="label"><span class="font-semibold">Importo</span><input v-model="pay.amount" inputmode="decimal" :placeholder="b.due_label" :class="field" /></label>
                        <label :class="label"><span class="font-semibold">Data</span><input v-model="pay.date" type="date" :class="field" required /></label>
                        <label :class="label"><span class="font-semibold">Conto</span><select v-model="pay.account_id" :class="field"><option v-for="ac in accounts" :key="ac.id" :value="ac.id">{{ ac.name }}</option></select></label>
                        <label :class="label"><span class="font-semibold">Modalità</span><select v-model="pay.method" :class="field"><option value="">Quella del conto</option><option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                        <div class="flex gap-3 sm:col-span-4">
                            <button type="submit" class="btn btn-primary" :disabled="pay.processing">Registra l'incasso</button>
                            <button type="button" class="btn btn-secondary" @click="paying = null">Annulla</button>
                            <span class="self-center text-sm text-muted">Va in prima nota, con ricevuta se chi paga è in anagrafica.</span>
                        </div>
                    </form>
                </li>
            </ul>
        </section>

        <section v-if="resource && resource.typology === 'seasonal'" class="mb-6 card p-4 sm:p-5" aria-labelledby="wait-title">
            <h2 id="wait-title" class="mb-3 text-lg font-bold">Lista d'attesa</h2>
            <p v-if="!resource.waitlist.length" class="mb-3 text-sm text-muted">Nessuno in attesa.</p>
            <ol class="mb-3 list-decimal space-y-1 pl-5 text-sm">
                <li v-for="w in resource.waitlist" :key="w.person_id">
                    <Link :href="`/gestionale/soci/${w.person_id}`" class="font-semibold text-accent hover:underline">{{ w.name }}</Link>
                    <span class="text-muted"> · dal {{ w.since }}<template v-if="w.note"> · {{ w.note }}</template></span>
                    <button v-if="canEdit" type="button" class="ml-3 text-danger hover:underline" @click="leave(w)">togli</button>
                </li>
            </ol>
            <form v-if="canEdit" class="flex flex-wrap items-end gap-3" @submit.prevent="submitWait">
                <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="wait-person" class="font-semibold">Aggiungi in lista</label><PersonPicker id="wait-person" v-model="wait.person_id" /></div>
                <label class="space-y-1 text-sm"><span class="block font-semibold">Note</span><input v-model="wait.note" :class="field" maxlength="255" placeholder="es. barca 8 m" /></label>
                <button type="submit" class="btn btn-secondary" :disabled="!wait.person_id || wait.processing">Aggiungi</button>
            </form>
            <p class="mt-2 text-xs text-muted">Quando la risorsa viene assegnata a chi è in lista, esce dalla lista da sola.</p>
        </section>

        <section v-if="resource && canEdit && !resource.archived" class="mb-6 card p-4 sm:p-5" aria-labelledby="block-title">
            <h2 id="block-title" class="mb-3 text-lg font-bold">Blocca la risorsa</h2>
            <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="submitBlock">
                <label :class="label"><span class="font-semibold">Dal</span><input v-model="block.from" type="datetime-local" :class="field" required /></label>
                <label :class="label"><span class="font-semibold">Al</span><input v-model="block.to" type="datetime-local" :class="field" required /></label>
                <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Motivo</span><input v-model="block.note" :class="field" maxlength="255" placeholder="es. manutenzione, torneo, chiusura" /></label>
                <div class="sm:col-span-4"><button type="submit" class="btn btn-secondary" :disabled="block.processing">Blocca</button>
                    <span class="ml-3 text-sm text-muted">Non si blocca un periodo con prenotazioni: annullale prima.</span></div>
            </form>
        </section>

        <form class="card p-4 sm:p-5" @submit.prevent="save">
            <h2 v-if="resource" class="mb-3 text-lg font-bold">Impostazioni</h2>
            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Nome</span><input v-model="form.name" :class="field" required maxlength="160" placeholder="es. Campo 1, Sala feste, Posto barca 12" /></label>
                <label :class="label"><span class="font-semibold">Tipo</span><select v-model="form.category" :class="field"><option v-for="c in categories" :key="c.value" :value="c.value">{{ c.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Si prenota</span><select v-model="form.typology" :class="field"><option v-for="t in typologies" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Tariffa soci {{ unitWord }}</span><input v-model="form.fee" inputmode="decimal" placeholder="0 = gratuita" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Tariffa non soci {{ unitWord }}</span><input v-model="form.nonmember_fee" inputmode="decimal" placeholder="vuoto = come i soci" :class="field" /></label>
                <template v-if="hourly">
                    <label :class="label"><span class="font-semibold">Apre alle</span><input v-model="form.open_time" type="time" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Chiude alle</span><input v-model="form.close_time" type="time" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Minuti per fascia</span><input v-model.number="form.slot_minutes" type="number" min="5" max="1440" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Fasce minime di seguito</span><input v-model.number="form.min_slots" type="number" min="1" max="48" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Fasce massime di seguito</span><input v-model.number="form.max_slots" type="number" min="1" max="48" :class="field" /></label>
                </template>
                <label v-if="!seasonal" :class="label"><span class="font-semibold">Anticipo massimo (giorni)</span><input v-model.number="form.advance_days" type="number" min="0" max="730" :class="field" /><span class="text-muted">0 = nessun limite; vale per le prenotazioni dei soci</span></label>
                <template v-if="!seasonal">
                    <label :class="label"><span class="font-semibold">Ospiti ammessi</span><input v-model.number="form.guests_max" type="number" min="0" max="100" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Supplemento per ospite</span><input v-model="form.guest_fee" inputmode="decimal" :class="field" /></label>
                </template>
                <label :class="label"><span class="font-semibold">Voce di entrata</span><select v-model="form.category_id" :class="field"><option value="">Altre entrate</option><option v-for="c in incomeCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label v-if="hourly" :class="[label, 'sm:col-span-3']"><span class="font-semibold">Tariffe a fasce</span>
                    <textarea v-model="form.rates" rows="2" :class="field" placeholder="feriali 18:00-22:00 15,00&#10;weekend 08:00-22:00 20" />
                    <span class="text-muted">Una per riga: giorni (tutti, feriali, weekend), orario, prezzo a fascia per i soci. Fuori dalle fasce vale la tariffa soci.</span></label>
                <label v-if="!seasonal" :class="[label, 'sm:col-span-3']"><span class="font-semibold">Servizi aggiuntivi</span>
                    <textarea v-model="form.services" rows="2" :class="field" placeholder="Aperitivo 5,00&#10;Allestimento 30" />
                    <span class="text-muted">Uno per riga, nome e prezzo: si scelgono alla prenotazione.</span></label>
                <label :class="[label, 'sm:col-span-3']"><span class="font-semibold">Descrizione</span><textarea v-model="form.description" rows="2" :class="field" maxlength="2000" /></label>
            </fieldset>
            <div v-if="canEdit" class="mt-4 flex flex-wrap gap-3">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ resource ? 'Salva' : 'Crea la risorsa' }}</button>
                <button v-if="resource" type="button" class="btn btn-secondary" @click="archive(!resource.archived)">{{ resource.archived ? 'Riattiva' : 'Archivia' }}</button>
            </div>
        </form>
    </AppLayout>
</template>
