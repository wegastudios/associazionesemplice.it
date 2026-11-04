<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    year: Number,
    years: Array,
    account: String,
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
const mode = ref(null);

const filter = (changes) => router.get('/gestionale/prima-nota', { anno: props.year, conto: props.account, ...changes }, { preserveState: true });

const income = useForm({
    date: today,
    account_id: openAccounts.value[0]?.id ?? '',
    method: '',
    receipt: true,
    person_id: '',
    document_ref: '',
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
});
const transfer = useForm({
    date: today,
    from_account_id: openAccounts.value[0]?.id ?? '',
    to_account_id: openAccounts.value[1]?.id ?? '',
    amount: '',
    description: '',
});

const done = (form) => ({ preserveScroll: true, onSuccess: () => { form.reset(); mode.value = null; } });
const submitIncome = () => income.post('/gestionale/prima-nota/incassi', done(income));
const submitExpense = () => expense.post('/gestionale/prima-nota/spese', done(expense));
const submitTransfer = () => transfer.post('/gestionale/prima-nota/giroconti', done(transfer));

const voidReason = ref('');
const voiding = ref(null);
const submitVoid = (id) =>
    router.post(`/gestionale/prima-nota/${id}/storno`, { reason: voidReason.value }, {
        preserveScroll: true,
        onSuccess: () => { voiding.value = null; voidReason.value = ''; },
    });

const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Prima nota" />
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-2xl font-bold">Prima nota</h1>
            <div class="flex flex-wrap gap-3 text-sm">
                <label class="space-y-1">
                    <span class="block font-semibold">Esercizio</span>
                    <select :class="field" :value="year" @change="filter({ anno: $event.target.value })">
                        <option v-for="y in years" :key="y.year" :value="y.year">{{ y.year }}{{ y.status === 'closed' ? ' (chiuso)' : '' }}</option>
                    </select>
                </label>
                <label class="space-y-1">
                    <span class="block font-semibold">Conto</span>
                    <select :class="field" :value="account" @change="filter({ conto: $event.target.value })">
                        <option value="">Tutti</option>
                        <option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option>
                    </select>
                </label>
            </div>
        </div>

        <ul class="mb-6 grid gap-3 sm:grid-cols-3">
            <li v-for="a in accounts" :key="a.id" class="rounded-2xl border border-line bg-card p-4">
                <p class="text-sm text-muted">{{ a.name }}</p>
                <p class="text-xl font-bold tabular-nums">{{ balances[a.id] ?? '0,00 €' }}</p>
            </li>
        </ul>

        <div v-if="canEdit" class="mb-6 flex flex-wrap gap-2">
            <button v-for="m in [['income', 'Nuovo incasso'], ['expense', 'Nuova spesa'], ['transfer', 'Giroconto']]" :key="m[0]" type="button"
                class="rounded-lg border border-line px-4 py-2 font-semibold"
                :class="mode === m[0] ? 'bg-accent text-accent-ink' : 'bg-card hover:border-accent'"
                :aria-pressed="mode === m[0]" @click="mode = mode === m[0] ? null : m[0]">{{ m[1] }}</button>
        </div>

        <form v-if="mode === 'income'" class="mb-8 space-y-4 rounded-2xl border border-line bg-card p-5" @submit.prevent="submitIncome">
            <h2 class="text-lg font-bold">Nuovo incasso</h2>
            <div class="grid gap-3 sm:grid-cols-4">
                <label class="space-y-1 text-sm"><span class="font-semibold">Data</span><input v-model="income.date" type="date" :class="field" required /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Conto</span>
                    <select v-model="income.account_id" :class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Modalità</span>
                    <select v-model="income.method" :class="field"><option value="">Quella del conto</option><option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option></select></label>
                <label class="flex items-end gap-2 pb-2 text-sm"><input v-model="income.receipt" type="checkbox" class="size-4 accent-indaco" /> Emetti la ricevuta</label>
            </div>
            <div class="space-y-1 text-sm">
                <label for="payer" class="font-semibold">Chi paga (facoltativo)</label>
                <PersonPicker id="payer" v-model="income.person_id" />
            </div>
            <fieldset v-for="(line, i) in income.lines" :key="i" class="grid gap-3 rounded-lg border border-line p-3 sm:grid-cols-[2fr_1fr_2fr_1fr_auto]">
                <legend class="px-1 text-sm font-semibold">Voce {{ i + 1 }}</legend>
                <select v-model="line.category_id" :class="field" aria-label="Categoria"><option v-for="c in incomeCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select>
                <input v-model="line.amount" inputmode="decimal" placeholder="Importo, es. 30,00" :class="field" aria-label="Importo" required />
                <input v-model="line.description" placeholder="Descrizione" :class="field" aria-label="Descrizione" />
                <input v-model="line.competence_month" type="month" :class="field" aria-label="Mese di competenza" />
                <button v-if="income.lines.length > 1" type="button" class="text-sm font-semibold text-danger" @click="removeLine(i)">Togli</button>
                <p v-if="income.errors[`lines.${i}.amount`]" class="text-sm text-danger sm:col-span-5">{{ income.errors[`lines.${i}.amount`] }}</p>
            </fieldset>
            <div class="flex flex-wrap gap-3">
                <button type="button" class="rounded-lg border border-line px-3 py-2 text-sm font-semibold" @click="addLine">Aggiungi una voce</button>
                <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="income.processing">Registra l'incasso</button>
            </div>
        </form>

        <form v-if="mode === 'expense'" class="mb-8 space-y-4 rounded-2xl border border-line bg-card p-5" @submit.prevent="submitExpense">
            <h2 class="text-lg font-bold">Nuova spesa</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="space-y-1 text-sm"><span class="font-semibold">Data</span><input v-model="expense.date" type="date" :class="field" required /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Conto</span>
                    <select v-model="expense.account_id" :class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Categoria</span>
                    <select v-model="expense.category_id" :class="field"><option v-for="c in expenseCategories" :key="c.id" :value="c.id">{{ c.name }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Importo</span><input v-model="expense.amount" inputmode="decimal" placeholder="es. 120,00" :class="field" required /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Descrizione</span><input v-model="expense.description" :class="field" /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Documento</span><input v-model="expense.document_ref" placeholder="n. fattura o scontrino" :class="field" /></label>
            </div>
            <p v-if="expense.errors.amount" class="text-sm text-danger">{{ expense.errors.amount }}</p>
            <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="expense.processing">Registra la spesa</button>
        </form>

        <form v-if="mode === 'transfer'" class="mb-8 space-y-4 rounded-2xl border border-line bg-card p-5" @submit.prevent="submitTransfer">
            <h2 class="text-lg font-bold">Giroconto</h2>
            <div class="grid gap-3 sm:grid-cols-4">
                <label class="space-y-1 text-sm"><span class="font-semibold">Data</span><input v-model="transfer.date" type="date" :class="field" required /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Da</span>
                    <select v-model="transfer.from_account_id" :class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">A</span>
                    <select v-model="transfer.to_account_id" :class="field"><option v-for="a in openAccounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Importo</span><input v-model="transfer.amount" inputmode="decimal" :class="field" required /></label>
            </div>
            <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="transfer.processing">Registra il giroconto</button>
        </form>

        <div class="overflow-x-auto rounded-2xl border border-line bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Data</th>
                        <th class="px-4 py-3 font-semibold">Descrizione</th>
                        <th class="px-4 py-3 font-semibold">Categoria</th>
                        <th class="px-4 py-3 font-semibold">Conto</th>
                        <th class="px-4 py-3 text-right font-semibold">Importo</th>
                        <th class="px-4 py-3"><span class="sr-only">Azioni</span></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="6" class="px-4 py-6 text-center text-muted">Nessun movimento in questo esercizio.</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line" :class="r.voided ? 'text-muted line-through' : ''">
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ r.date }}</td>
                        <td class="px-4 py-3">
                            {{ r.description || r.type_label }}
                            <span v-if="r.receipt" class="ml-1 rounded bg-surface px-1.5 py-0.5 text-xs no-underline">ricevuta {{ r.receipt }}</span>
                            <span v-if="r.voided" class="block text-xs no-underline">Stornato: {{ r.void_reason }}</span>
                        </td>
                        <td class="px-4 py-3">{{ r.category }}</td>
                        <td class="px-4 py-3">{{ r.account }} · {{ r.method }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums">{{ r.amount }}</td>
                        <td class="px-4 py-3 text-right">
                            <template v-if="canEdit && !r.voided">
                                <form v-if="voiding === r.id" class="flex gap-2" @submit.prevent="submitVoid(r.id)">
                                    <input v-model="voidReason" required placeholder="Motivo" :class="field" aria-label="Motivo dello storno" />
                                    <button type="submit" class="font-semibold text-danger">Conferma</button>
                                </form>
                                <button v-else type="button" class="font-semibold text-accent hover:underline" @click="voiding = r.id">Storna</button>
                            </template>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav class="mt-4 flex justify-between text-sm font-semibold" aria-label="Pagine">
            <Link v-if="prev" :href="prev" class="text-accent">← Più recenti</Link><span v-else />
            <Link v-if="next" :href="next" class="text-accent">Meno recenti →</Link>
        </nav>
    </AppLayout>
</template>
