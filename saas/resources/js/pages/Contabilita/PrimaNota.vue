<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import Sheet from '../../components/ui/Sheet.vue';
import { ArrowDownLeft, ArrowLeftRight, ArrowUpRight, Download, Ellipsis, FileText, ListFilter, Upload, Wallet } from '../../components/ui/icons.js';

const props = defineProps({
    year: Number,
    years: Array,
    account: String,
    activity: String,
    activities: Array,
    accounts: Array,
    categories: Array,
    methods: Array,
    rows: Array,
    next: String,
    prev: String,
    balances: Object,
    canEdit: Boolean,
});

const today = new Date().toISOString().slice(0, 10);
const openAccounts = computed(() => props.accounts.filter((a) => a.open));
const incomeCategories = computed(() => props.categories.filter((c) => c.income && !c.adjustment));
const expenseCategories = computed(() => props.categories.filter((c) => c.expense && !c.adjustment));

// «Registra un incasso» dal menu Nuovo arriva con ?nuovo=incasso|spesa|giroconto.
const wanted = new URLSearchParams(usePage().url.split('?')[1] ?? '').get('nuovo');
const mode = ref(props.canEdit ? ({ incasso: 'income', spesa: 'expense', giroconto: 'transfer' })[wanted] ?? null : null);
const open = (m) => computed({ get: () => mode.value === m, set: (v) => { mode.value = v ? m : null; } });
const showIncome = open('income');
const showExpense = open('expense');
const showTransfer = open('transfer');
const showFilters = ref(false);
const showTools = ref(false);
const detail = ref(null);

const filtersActive = computed(() => (props.account ? 1 : 0) + (props.activity ? 1 : 0));
const filter = (changes) => router.get('/gestionale/prima-nota', { anno: props.year, conto: props.account, attivita: props.activity, ...changes }, { preserveState: true, replace: true });
const sendReceipt = (r) => router.post(`/gestionale/ricevute/${r.receipt_id}/invia`, {}, { preserveScroll: true });
const setActivity = (r, id) => router.post(`/gestionale/prima-nota/${r.id}/attivita`, { activity_id: id || null }, { preserveScroll: true });

const income = useForm({
    date: today,
    account_id: openAccounts.value[0]?.id ?? '',
    method: '',
    receipt: true,
    person_id: '',
    document_ref: '',
    activity_id: '',
    lines: [{ category_id: incomeCategories.value[0]?.id ?? '', amount: '', description: '', competence_month: '' }],
});
const addLine = () => income.lines.push({ category_id: incomeCategories.value[0]?.id ?? '', amount: '', description: '', competence_month: '' });
const removeLine = (i) => income.lines.splice(i, 1);

const expense = useForm({
    date: today,
    account_id: openAccounts.value[0]?.id ?? '',
    category_id: expenseCategories.value[0]?.id ?? '',
    amount: '',
    method: '',
    description: '',
    document_ref: '',
    activity_id: '',
});
const transfer = useForm({
    date: today,
    from_account_id: openAccounts.value[0]?.id ?? '',
    to_account_id: openAccounts.value[1]?.id ?? '',
    amount: '',
    description: '',
});

const done = (form) => ({ preserveScroll: true, onSuccess: () => { form.reset(); mode.value = null; } });
// Un errore del server (esercizio chiuso, conto chiuso, dati non validi) si legge dentro al foglio, non sotto il velo.
const sheetError = (form) => usePage().props.errors?.ledger ?? Object.values(form.errors)[0] ?? null;
const noEmpty = (d) => ({ ...d, activity_id: d.activity_id || null });
const submitIncome = () => income.transform(noEmpty).post('/gestionale/prima-nota/incassi', done(income));
const submitExpense = () => expense.transform(noEmpty).post('/gestionale/prima-nota/spese', done(expense));
const submitTransfer = () => transfer.post('/gestionale/prima-nota/giroconti', done(transfer));

const voidReason = ref('');
const confirmVoid = ref(false);
const submitVoid = (id) =>
    router.post(`/gestionale/prima-nota/${id}/storno`, { reason: voidReason.value }, {
        preserveScroll: true,
        onSuccess: () => { detail.value = null; confirmVoid.value = false; voidReason.value = ''; },
    });
const showDetail = computed({ get: () => detail.value !== null, set: (v) => { if (!v) { detail.value = null; confirmVoid.value = false; voidReason.value = ''; } } });

// I movimenti, raggruppati per giorno (arrivano già dal più recente).
const days = computed(() => {
    const out = [];
    for (const r of props.rows) {
        if (!out.length || out[out.length - 1].date !== r.date) out.push({ date: r.date, rows: [] });
        out[out.length - 1].rows.push(r);
    }
    return out;
});
const kind = (r) => (r.type === 'income' || r.type === 'transfer_in' ? 'in' : (r.type === 'transfer_out' ? 'swap' : 'out'));
const icons = { in: ArrowDownLeft, out: ArrowUpRight, swap: ArrowLeftRight };
const tints = { in: 'bg-ok-soft text-ok-ink', out: 'bg-surface text-muted', swap: 'bg-info-soft text-info-ink' };
const label = 'block space-y-1.5 text-sm';
</script>

<template>
    <Head title="Prima nota" />
    <AppLayout>
        <PageHeader title="Prima nota" :subtitle="`Esercizio ${year} · incassi (entrate) e spese (uscite) dell’anno`">
            <template #actions>
                <template v-if="canEdit">
                    <button type="button" class="btn btn-primary hidden lg:inline-flex" @click="mode = 'income'">Registra un incasso</button>
                    <button type="button" class="btn btn-secondary hidden lg:inline-flex" @click="mode = 'expense'">Registra una spesa</button>
                    <button type="button" class="btn btn-secondary hidden lg:inline-flex" @click="mode = 'transfer'">Giroconto</button>
                </template>
            </template>
        </PageHeader>

        <ul class="-mx-4 mb-4 flex gap-3 overflow-x-auto px-4 pb-1 lg:mx-0 lg:grid lg:grid-cols-3 lg:overflow-visible lg:px-0" aria-label="Saldi dei conti">
            <li v-for="a in accounts" :key="a.id" class="card min-w-40 flex-1 p-4">
                <p class="caption truncate">{{ a.name }}</p>
                <p class="mt-0.5 text-xl font-bold tabular-nums">{{ balances[a.id] ?? '0,00 €' }}</p>
            </li>
        </ul>

        <div class="mb-4 flex gap-2">
            <button type="button" class="btn btn-secondary flex-1 justify-start sm:flex-none" @click="showFilters = true">
                <ListFilter class="size-5 text-muted" aria-hidden="true" />Filtri
                <span v-if="filtersActive" class="badge badge-info">{{ filtersActive }}</span>
            </button>
            <button type="button" class="btn btn-secondary shrink-0 px-3.5" aria-label="Altri strumenti" @click="showTools = true"><Ellipsis class="size-5" aria-hidden="true" /></button>
        </div>

        <template v-if="rows.length">
            <section v-for="d in days" :key="d.date" class="mb-4">
                <h2 class="caption mb-1.5 px-1 font-semibold">{{ d.date }}</h2>
                <ul class="list-group">
                    <li v-for="r in d.rows" :key="r.id">
                        <button type="button" class="list-row" :class="r.voided ? 'opacity-60' : ''" @click="detail = r">
                            <span :class="['grid size-10 shrink-0 place-items-center rounded-full', tints[kind(r)]]"><component :is="icons[kind(r)]" class="size-5" aria-hidden="true" /></span>
                            <span class="min-w-0 flex-1">
                                <span class="line-clamp-2 font-semibold" :class="r.voided ? 'line-through' : ''">{{ r.description || r.type_label }}</span>
                                <span class="caption block truncate">{{ r.category }} – {{ r.account }}<template v-if="r.activity"> – {{ r.activity }}</template></span>
                            </span>
                            <span class="shrink-0 text-right">
                                <span class="block font-bold tabular-nums" :class="[kind(r) === 'in' ? 'text-ok-ink' : '', r.voided ? 'line-through' : '']">{{ r.amount }}</span>
                                <span v-if="r.voided" class="badge badge-neutral">Stornato</span>
                                <span v-else-if="r.receipt" class="caption block">ricevuta {{ r.receipt }}</span>
                            </span>
                        </button>
                    </li>
                </ul>
            </section>
        </template>
        <div v-else class="card">
            <EmptyState title="Nessun movimento" :text="filtersActive ? 'Con questi filtri non c\'è niente: prova a toglierne qualcuno.' : `Nell'esercizio ${year} non c'è ancora nessun movimento.`">
                <template #icon><Wallet class="size-6" /></template>
                <button v-if="canEdit && !filtersActive" type="button" class="btn btn-primary" @click="mode = 'income'">Registra un incasso</button>
                <button v-if="canEdit && !filtersActive" type="button" class="btn btn-secondary" @click="mode = 'expense'">Registra una spesa</button>
            </EmptyState>
        </div>

        <nav v-if="prev || next" class="mt-4 flex justify-between gap-3" aria-label="Pagine">
            <Link v-if="prev" :href="prev" class="btn btn-secondary">Più recenti</Link><span v-else />
            <Link v-if="next" :href="next" class="btn btn-secondary">Meno recenti</Link>
        </nav>

        <!-- Filtri -->
        <Sheet v-model="showFilters" title="Filtri">
            <div class="space-y-4">
                <label :class="label"><span class="font-semibold">Esercizio</span>
                    <select class="field" :value="year" @change="filter({ anno: $event.target.value })">
                        <option v-for="y in years" :key="y.year" :value="y.year">{{ y.year }}{{ y.status === 'closed' ? ' (chiuso)' : '' }}</option>
                    </select></label>
                <label :class="label"><span class="font-semibold">Conto</span>
                    <select class="field" :value="account" @change="filter({ conto: $event.target.value })">
                        <option value="">Tutti i conti</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select></label>
                <label v-if="activities.length" :class="label"><span class="font-semibold">Attività</span>
                    <select class="field" :value="activity" @change="filter({ attivita: $event.target.value })">
                        <option value="">Tutte le attività</option>
                        <option v-for="a in activities" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select></label>
                <button type="button" class="btn btn-primary btn-block" @click="showFilters = false">Mostra i movimenti</button>
            </div>
        </Sheet>

        <Sheet v-model="showTools" title="Altri strumenti">
            <ul class="list-group">
                <li><a :href="`/gestionale/prima-nota/esporta?anno=${year}`" class="list-row" @click="showTools = false"><Download class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Esporta l'esercizio (CSV)</span></a></li>
                <li v-if="canEdit"><Link href="/gestionale/prima-nota/importa" class="list-row"><Upload class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Importa da Excel o CSV</span></Link></li>
                <li v-if="canEdit"><button type="button" class="list-row" @click="showTools = false; mode = 'transfer'"><ArrowLeftRight class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Giroconto tra conti</span></button></li>
            </ul>
        </Sheet>

        <!-- Dettaglio di un movimento -->
        <Sheet v-model="showDetail" :title="detail?.description || detail?.type_label || 'Movimento'" :description="detail ? `${detail.date} – ${detail.category}` : null">
            <template v-if="detail">
                <p class="mb-4 text-3xl font-bold tabular-nums" :class="[kind(detail) === 'in' ? 'text-ok-ink' : '', detail.voided ? 'line-through' : '']">{{ detail.amount }}</p>
                <dl class="mb-4 grid grid-cols-[auto_1fr] gap-x-4 gap-y-1.5 text-sm">
                    <dt class="text-muted">Conto</dt><dd class="font-semibold">{{ detail.account }}</dd>
                    <dt class="text-muted">Modalità</dt><dd class="font-semibold">{{ detail.method }}</dd>
                    <template v-if="detail.activity"><dt class="text-muted">Attività</dt><dd class="font-semibold">{{ detail.activity }}</dd></template>
                    <template v-if="detail.voided"><dt class="text-muted">Stornato</dt><dd class="font-semibold">{{ detail.void_reason }}</dd></template>
                </dl>
                <div v-if="detail.receipt && !detail.voided" class="mb-4 flex flex-wrap gap-2">
                    <a :href="`/gestionale/ricevute/${detail.receipt_id}.pdf`" target="_blank" class="btn btn-secondary flex-1"><FileText class="size-5" aria-hidden="true" />Ricevuta {{ detail.receipt }}</a>
                    <button v-if="canEdit" type="button" class="btn btn-secondary flex-1" @click="sendReceipt(detail)">Invia per email</button>
                </div>
                <label v-if="canEdit && !detail.voided && (detail.type === 'income' || detail.type === 'expense') && activities.length" :class="[label, 'mb-4']"><span class="font-semibold">Attività del movimento</span>
                    <select class="field" :value="detail.activity_id ?? ''" @change="setActivity(detail, $event.target.value)">
                        <option value="">Nessuna attività</option><option v-for="a in activities" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select></label>
                <template v-if="canEdit && !detail.voided">
                    <button v-if="!confirmVoid" type="button" class="btn btn-danger btn-block" @click="confirmVoid = true">Storna il movimento</button>
                    <form v-else class="space-y-3 rounded-xl border border-danger/30 bg-danger-soft p-4" @submit.prevent="submitVoid(detail.id)">
                        <p class="text-sm font-semibold text-danger">Il movimento resta in elenco ma non conta più. Scrivi perché lo storni.</p>
                        <input v-model="voidReason" required maxlength="190" class="field" placeholder="Motivo dello storno" aria-label="Motivo dello storno" data-autofocus />
                        <div class="flex gap-2"><button type="button" class="btn btn-secondary flex-1" @click="confirmVoid = false">Lascia com'è</button><button type="submit" class="btn btn-danger flex-1">Storna</button></div>
                    </form>
                </template>
            </template>
        </Sheet>

        <!-- Nuovo incasso -->
        <Sheet v-model="showIncome" title="Registra un incasso" description="Quote, contributi, donazioni">
            <form class="space-y-4" @submit.prevent="submitIncome">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="income.date" type="date" class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Conto</span>
                        <select v-model="income.account_id" class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                </div>
                <div :class="label">
                    <label for="payer" class="font-semibold">Chi paga <span class="font-medium text-muted">(facoltativo)</span></label>
                    <PersonPicker id="payer" v-model="income.person_id" />
                </div>
                <fieldset v-for="(line, i) in income.lines" :key="i" class="space-y-3 rounded-xl border border-line p-3">
                    <legend class="px-1 text-sm font-semibold">{{ income.lines.length > 1 ? `Voce ${i + 1}` : 'Cosa incassi' }}</legend>
                    <select v-model="line.category_id" class="field" aria-label="Categoria"><option v-for="c in incomeCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                    <input v-model="line.amount" inputmode="decimal" placeholder="Importo, es. 30,00" class="field" aria-label="Importo" required />
                    <input v-model="line.description" placeholder="Descrizione (facoltativa)" class="field" aria-label="Descrizione" />
                    <label :class="label"><span class="caption">Mese di competenza (facoltativo)</span><input v-model="line.competence_month" type="month" class="field" /></label>
                    <button v-if="income.lines.length > 1" type="button" class="text-sm font-semibold text-danger" @click="removeLine(i)">Togli questa voce</button>
                    <p v-if="income.errors[`lines.${i}.amount`]" class="text-sm font-semibold text-danger">{{ income.errors[`lines.${i}.amount`] }}</p>
                </fieldset>
                <button type="button" class="btn btn-ghost btn-sm" @click="addLine">Aggiungi un'altra voce</button>
                <details class="rounded-xl border border-line px-4 py-3 text-sm">
                    <summary class="cursor-pointer font-semibold">Altre opzioni</summary>
                    <div class="mt-3 space-y-4">
                        <label :class="label"><span class="font-semibold">Modalità di pagamento</span>
                            <select v-model="income.method" class="field"><option value="">Quella del conto</option><option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                        <label v-if="activities.length" :class="label"><span class="font-semibold">Attività</span>
                            <select v-model="income.activity_id" class="field"><option value="">Nessuna</option><option v-for="a in activities" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                        <label class="flex items-center gap-3"><input v-model="income.receipt" type="checkbox" />Emetti la ricevuta</label>
                    </div>
                </details>
${E//FORM/income}
                <button type="submit" class="btn btn-primary btn-block" :disabled="income.processing">Registra l'incasso</button>
            </form>
        </Sheet>

        <!-- Nuova spesa -->
        <Sheet v-model="showExpense" title="Registra una spesa" description="Affitto, materiali, utenze">
            <form class="space-y-4" @submit.prevent="submitExpense">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="expense.date" type="date" class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Importo</span><input v-model="expense.amount" inputmode="decimal" placeholder="es. 120,00" class="field" required /></label>
                </div>
                <p v-if="expense.errors.amount" class="text-sm font-semibold text-danger">{{ expense.errors.amount }}</p>
                <label :class="label"><span class="font-semibold">Categoria</span>
                    <select v-model="expense.category_id" class="field"><option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">Da quale conto</span>
                    <select v-model="expense.account_id" class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">Descrizione <span class="font-medium text-muted">(facoltativa)</span></span><input v-model="expense.description" class="field" /></label>
                <details class="rounded-xl border border-line px-4 py-3 text-sm">
                    <summary class="cursor-pointer font-semibold">Altre opzioni</summary>
                    <div class="mt-3 space-y-4">
                        <label :class="label"><span class="font-semibold">Documento</span><input v-model="expense.document_ref" placeholder="n. fattura o scontrino" class="field" /></label>
                        <label :class="label"><span class="font-semibold">Modalità di pagamento</span>
                            <select v-model="expense.method" class="field"><option value="">Quella del conto</option><option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                        <label v-if="activities.length" :class="label"><span class="font-semibold">Attività</span>
                            <select v-model="expense.activity_id" class="field"><option value="">Nessuna</option><option v-for="a in activities" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    </div>
                </details>
${E//FORM/expense}
                <button type="submit" class="btn btn-primary btn-block" :disabled="expense.processing">Registra la spesa</button>
            </form>
        </Sheet>

        <!-- Giroconto -->
        <Sheet v-model="showTransfer" title="Giroconto" description="Sposta soldi da un conto all'altro">
            <form class="space-y-4" @submit.prevent="submitTransfer">
                <div class="grid gap-4 sm:grid-cols-2">
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="transfer.date" type="date" class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Importo</span><input v-model="transfer.amount" inputmode="decimal" placeholder="es. 200,00" class="field" required /></label>
                </div>
                <label :class="label"><span class="font-semibold">Da</span>
                    <select v-model="transfer.from_account_id" class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">A</span>
                    <select v-model="transfer.to_account_id" class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">Descrizione <span class="font-medium text-muted">(facoltativa)</span></span><input v-model="transfer.description" class="field" /></label>
${E//FORM/transfer}
                <button type="submit" class="btn btn-primary btn-block" :disabled="transfer.processing">Registra il giroconto</button>
            </form>
        </Sheet>
    </AppLayout>
</template>
