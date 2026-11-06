<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    activity: Object, session: Object, bookings: Array, waitlist: Array, priceLists: Array, fee: String, accounts: Array, methods: Array,
    today: String, canEdit: Boolean, canAccounting: Boolean,
});

const book = useForm({ person_id: '', price: '' });
const submitBook = () => book.transform((d) => ({ person_id: d.person_id, price_list: d.price && d.price !== '_package' ? d.price : null, use_package: d.price === '_package' }))
    .post(`/gestionale/attivita/date/${props.session.id}/prenota`, { preserveScroll: true, onSuccess: () => book.reset() });
const wait = useForm({ person_id: '' });
const submitWait = () => wait.post(`/gestionale/attivita/date/${props.session.id}/attesa`, { preserveScroll: true, onSuccess: () => wait.reset() });
const leave = (w) => router.post(`/gestionale/attivita/date/${props.session.id}/attesa/${w.person_id}/esci`, {}, { preserveScroll: true });
const cancelBooking = (b) => confirm(`Annullare la prenotazione di ${b.name}?`) && router.post(`/gestionale/attivita/prenotazioni/${b.id}/annulla`, {}, { preserveScroll: true });

const edit = useForm({ start: props.session.start ?? '', end: props.session.end ?? '', location: props.session.location ?? '', capacity: props.session.capacity ?? '' });
const submitEdit = () => edit.transform((d) => ({ ...d, start: d.start || null, end: d.end || null, capacity: d.capacity || null }))
    .put(`/gestionale/attivita/date/${props.session.id}`, { preserveScroll: true });
const cancelSession = (future) => confirm(future ? 'Annullare questa data e tutte le successive?' : 'Annullare questa data?')
    && router.post(`/gestionale/attivita/date/${props.session.id}/annulla`, { future }, { preserveScroll: true });

const paying = ref(null);
const pay = useForm({ account_id: props.accounts[0]?.id ?? '', date: props.today, amount: '', method: '' });
const submitPay = () => pay.transform((d) => ({ ...d, amount: d.amount || null, method: d.method || null }))
    .post(`/gestionale/attivita/prenotazioni/${paying.value}/incassa`, { preserveScroll: true, onSuccess: () => { paying.value = null; } });

const field = 'field';
const label = 'space-y-1 text-sm';
const states = { free: 'gratuita', paid: 'pagata', partial: 'pagata in parte', unpaid: 'da pagare' };
</script>

<template>
    <Head :title="`${activity.name} · ${session.label}`" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link :href="`/gestionale/attivita/${activity.id}`" class="text-accent hover:underline">← {{ activity.name }}</Link></p>
        <div class="mb-4 flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold">{{ session.label }}<template v-if="session.start"> · {{ session.start }}<template v-if="session.end">–{{ session.end }}</template></template></h1>
            <span v-if="session.cancelled" class="rounded bg-danger/15 px-2 py-0.5 text-sm text-danger">annullata</span>
        </div>
        <p class="mb-6 text-sm text-muted">
            {{ session.taken }} prenotati<template v-if="session.capacity"> su {{ session.capacity }} posti</template><template v-else> (posti senza limite)</template><template v-if="session.location"> · {{ session.location }}</template>
            · <Link :href="`/gestionale/attivita/${activity.id}/presenze?mese=${session.date.slice(0, 7)}&giorno=${session.date}`" class="font-semibold text-accent hover:underline">presenze</Link>
        </p>

        <section class="mb-6 card p-4 sm:p-5" aria-labelledby="bk-title">
            <h2 id="bk-title" class="mb-3 text-lg font-bold">Prenotati</h2>
            <p v-if="!bookings.length" class="mb-3 text-sm text-muted">Nessuna prenotazione.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="b in bookings" :key="b.id" class="py-3" :class="b.active ? '' : 'text-muted line-through'">
                    <div class="flex flex-wrap items-center gap-3">
                        <Link :href="`/gestionale/soci/${b.person_id}`" class="font-semibold text-accent hover:underline">{{ b.name }}</Link>
                        <span class="text-muted">{{ b.type }}</span>
                        <span v-if="b.list" class="rounded bg-surface px-2 py-0.5 text-xs">{{ b.list }}<template v-if="b.from_package"> · dal pacchetto</template></span>
                        <span v-if="b.package_left !== null" class="text-xs text-muted">ingressi rimasti: {{ b.package_left }}</span>
                        <span class="text-muted">{{ b.fee }} · {{ states[b.state] }}</span>
                        <span v-if="b.active && b.due > 0" class="rounded bg-danger/15 px-2 py-0.5 text-danger">da incassare {{ b.due_label }}</span>
                        <span v-if="b.active" class="ml-auto flex gap-3">
                            <button v-if="canEdit && canAccounting && b.due > 0" type="button" class="font-semibold text-accent hover:underline" @click="paying = b.id">Incassa</button>
                            <button v-if="canEdit" type="button" class="text-danger hover:underline" @click="cancelBooking(b)">Annulla</button>
                        </span>
                    </div>
                    <form v-if="paying === b.id" class="mt-3 grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-4" @submit.prevent="submitPay">
                        <label :class="label"><span class="font-semibold">Importo</span><input v-model="pay.amount" inputmode="decimal" :placeholder="b.due_label" :class="field" /></label>
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
            <form v-if="canEdit && !session.cancelled && !activity.archived" class="mt-4 flex flex-wrap items-end gap-3" @submit.prevent="submitBook">
                <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="bk-person" class="font-semibold">Prenota una persona</label><PersonPicker id="bk-person" v-model="book.person_id" /></div>
                <label class="space-y-1 text-sm"><span class="block font-semibold">Prezzo</span>
                    <select v-model="book.price" :class="field">
                        <option value="">Contributo ({{ fee }} per i soci)</option>
                        <option v-for="l in priceLists" :key="l.key" :value="l.key">{{ l.label }}</option>
                        <option value="_package">Un ingresso da un pacchetto già preso</option>
                    </select></label>
                <button type="submit" class="btn btn-primary" :disabled="!book.person_id || book.processing || session.free === 0">Prenota</button>
                <span v-if="session.free === 0" class="text-sm text-danger">Posti esauriti: usa la lista d'attesa.</span>
            </form>
        </section>

        <section v-if="session.capacity" class="mb-6 card p-4 sm:p-5" aria-labelledby="wl-title">
            <h2 id="wl-title" class="mb-3 text-lg font-bold">Lista d'attesa</h2>
            <p v-if="!waitlist.length" class="mb-3 text-sm text-muted">Nessuno in attesa.</p>
            <ol class="mb-3 list-decimal space-y-1 pl-5 text-sm">
                <li v-for="w in waitlist" :key="w.person_id">{{ w.name }} <span class="text-muted">· dal {{ w.since }}</span>
                    <button v-if="canEdit" type="button" class="ml-3 text-danger hover:underline" @click="leave(w)">togli</button></li>
            </ol>
            <form v-if="canEdit && session.free === 0 && !session.cancelled" class="flex flex-wrap items-end gap-3" @submit.prevent="submitWait">
                <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="wl-person" class="font-semibold">Metti in lista</label><PersonPicker id="wl-person" v-model="wait.person_id" /></div>
                <button type="submit" class="btn btn-secondary" :disabled="!wait.person_id || wait.processing">Aggiungi</button>
            </form>
            <p class="mt-2 text-xs text-muted">Quando si libera un posto (un annullamento o più posti) entra da solo il primo della lista, al contributo normale.</p>
        </section>

        <section v-if="canEdit && !session.cancelled" class="card p-4 sm:p-5" aria-labelledby="ed-title">
            <h2 id="ed-title" class="mb-3 text-lg font-bold">Modifica la data</h2>
            <form class="grid gap-3 sm:grid-cols-4" @submit.prevent="submitEdit">
                <label :class="label"><span class="font-semibold">Dalle</span><input v-model="edit.start" type="time" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Alle</span><input v-model="edit.end" type="time" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Posti</span><input v-model="edit.capacity" type="number" min="1" :class="field" placeholder="senza limite" /></label>
                <label :class="label"><span class="font-semibold">Luogo</span><input v-model="edit.location" :class="field" maxlength="190" /></label>
                <div class="flex flex-wrap gap-3 sm:col-span-4">
                    <button type="submit" class="btn btn-primary" :disabled="edit.processing">Salva</button>
                    <button type="button" class="btn btn-danger" @click="cancelSession(false)">Annulla la data</button>
                    <button type="button" class="btn btn-danger" @click="cancelSession(true)">Annulla questa e le successive</button>
                </div>
            </form>
        </section>
    </AppLayout>
</template>
