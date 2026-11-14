<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ filter: String, rows: Array, year: Number, source: String, years: Array, versions: { type: Array, default: () => [] }, canExport: Boolean });
const filters = [{ value: '', label: 'Tutti' }, { value: 'in_force', label: 'In carica' }, { value: 'left', label: 'Cessati' }];
const params = (over = {}) => { const p = { ...(props.filter ? { stato: props.filter } : {}), ...(props.year ? { anno: props.year } : {}), ...over }; return Object.fromEntries(Object.entries(p).filter(([, v]) => v !== '' && v != null)); };
const q = () => { const s = new URLSearchParams(params()).toString(); return s ? `?${s}` : ''; };
</script>

<template>
    <Head title="Libro soci" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="text-accent hover:underline">← Soci</Link></p>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Libro soci</h1>
                <p class="text-sm text-muted">Tutti i soci in ordine di ingresso, con domanda, ammissione, cessazione e ultima quota. Ospiti e genitori non soci non ci sono.</p>
                <p v-if="versions.length" class="text-sm text-muted">Fotografie: <span v-for="(v, i) in versions" :key="v.version">versione {{ v.version }} del {{ v.date }} ({{ v.superseded ? 'superata dopo una riapertura' : 'in uso' }})<template v-if="i < versions.length - 1">; </template></span>.</p>
                <p v-if="year" class="text-sm font-semibold text-muted">Esercizio {{ year }}: chi ha avuto una tessera valida per almeno un giorno dell'anno. {{ source === 'snapshot' ? 'Fotografia alla chiusura: non cambia più, salvo riapertura.' : 'Esercizio aperto: si aggiorna fino alla chiusura.' }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm font-semibold">
                <label v-if="years.length" class="flex items-center gap-2">Esercizio<select class="rounded border border-line bg-surface px-2 py-1" :value="year ?? ''" @change="router.get('/gestionale/soci/libro', params({ anno: $event.target.value }), { preserveScroll: true })"><option value="">Tutti i soci</option><option v-for="y in years" :key="y.year" :value="y.year">{{ y.year }}{{ y.status === 'closed' ? ' (chiuso)' : '' }}</option></select></label>
                <nav class="flex gap-1 rounded-lg border border-line p-1" aria-label="Filtro">
                    <button v-for="f in filters" :key="f.value" type="button" class="rounded px-3 py-1" :class="filter === f.value ? 'bg-surface text-accent' : 'text-muted'" :aria-pressed="filter === f.value"
                        @click="router.get('/gestionale/soci/libro', params({ stato: f.value }), { preserveScroll: true })">{{ f.label }}</button>
                </nav>
                <template v-if="canExport">
                    <a :href="`/gestionale/soci/libro.pdf${q()}`" target="_blank" class="btn btn-primary">Stampa (PDF)</a>
                    <a :href="`/gestionale/soci/libro.csv${q()}`" class="text-accent hover:underline">CSV</a>
                </template>
            </div>
        </div>

        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr>
                        <th class="px-3 py-3 text-right font-semibold">N. e matricola</th>
                        <th class="px-3 py-3 font-semibold">Socio</th>
                        <th class="px-3 py-3 font-semibold">Nascita</th>
                        <th class="px-3 py-3 font-semibold">Residenza</th>
                        <th class="px-3 py-3 font-semibold">Tessera</th>
                        <th class="px-3 py-3 font-semibold">Ammissione</th>
                        <th class="px-3 py-3 font-semibold">Cessazione</th>
                        <th class="px-3 py-3 text-right font-semibold">Ultima quota</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="8" class="px-3 py-6 text-center text-muted">Nessun socio.</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line align-top" :class="r.in_force ? '' : 'text-muted'">
                        <td class="px-3 py-2 text-right tabular-nums">{{ r.n }}<span v-if="r.matricola" class="block text-xs text-muted">matr. {{ r.matricola }}</span></td>
                        <td class="px-3 py-2"><Link :href="`/gestionale/soci/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link><span class="block text-xs text-muted">{{ r.tax_code }}</span></td>
                        <td class="px-3 py-2">{{ r.birth }}</td>
                        <td class="px-3 py-2">{{ r.residence }}<span v-if="r.guardian" class="block text-xs text-muted">Tutore: {{ r.guardian }}</span></td>
                        <td class="px-3 py-2">{{ r.card }}<span v-if="r.level" class="block text-xs text-muted">{{ r.level }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2">{{ r.approved_on }}<span v-if="r.applied_on !== r.approved_on" class="block text-xs text-muted">domanda {{ r.applied_on }}</span></td>
                        <td class="px-3 py-2">{{ r.left_on }}<span v-if="r.left_reason" class="block text-xs text-muted">{{ r.left_reason }}</span></td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ r.fee }}<span v-if="r.fee_year" class="block text-xs text-muted">{{ r.fee_year }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
