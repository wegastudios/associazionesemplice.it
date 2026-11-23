<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';
import ImportProgress from '../../components/ImportProgress.vue';

const props = defineProps({ accounts: Array, preview: Object, job: Object });
const busy = () => props.job && ['queued', 'running'].includes(props.job.status) && !props.job.stalled;

const form = useForm({ file: null, default_account_id: '', apply: false });
const send = (apply) => {
    form.apply = apply;
    form.transform((d) => ({ ...d, default_account_id: d.default_account_id || null }))
        .post('/gestionale/prima-nota/importa', { forceFormData: true, preserveState: true, preserveScroll: true });
};
const euro = (c) => (c / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const badge = { create: 'bg-salvia/15', transfer: 'bg-salvia/15', paired: 'bg-surface', duplicate: 'bg-surface', skip: 'bg-giallo/25', error: 'bg-danger/15 text-danger' };
const field = 'field';
</script>

<template>
    <Head title="Importa la prima nota" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/prima-nota" class="text-accent hover:underline">← Prima nota</Link></p>
        <h1 class="mb-4 text-2xl font-bold">Importa la prima nota da Excel o CSV</h1>

        <ImportProgress :job="job" url="/gestionale/prima-nota/importa" unit="movimenti" v-slot="{ result }">
            <p>{{ result.income }} {{ result.income === 1 ? 'entrata' : 'entrate' }}, {{ result.expense }} {{ result.expense === 1 ? 'uscita' : 'uscite' }}, {{ result.transfer }} {{ result.transfer === 1 ? 'giroconto registrato' : 'giroconti registrati' }}<template v-if="result.accounts">, {{ result.accounts }} {{ result.accounts === 1 ? 'conto creato' : 'conti creati' }}</template>.</p>
            <p class="mt-3"><Link href="/gestionale/prima-nota" class="font-semibold text-accent hover:underline">Vai alla prima nota</Link></p>
        </ImportProgress>

        <section class="card p-4 sm:p-5">
            <p class="mb-2 text-sm text-muted">Servono le colonne <strong>Data</strong> e <strong>Importo</strong> (oppure <strong>Entrata</strong> e <strong>Uscita</strong>). Si riconoscono anche Tipo, Conto, Modalità, Voce, Attività, N. tessera, Persona, Descrizione, Competenza e Riferimento: il file che esporta la prima nota si ricarica così com'è.</p>
            <p class="mb-4 text-sm text-muted">I movimenti già presenti (stessa data, conto, importo, descrizione e riferimento) si saltano; i conti che non esistono si creano; i giroconti si riconoscono dalle due righe con la stessa data e lo stesso importo. Non si emettono ricevute. Prima vedi l'anteprima: nulla si salva finché non confermi.</p>
            <form class="grid gap-4 text-sm sm:grid-cols-2" @submit.prevent="send(false)">
                <label class="space-y-1"><span class="block font-semibold">File (.xlsx o .csv, al massimo 5 MB)</span>
                    <input type="file" accept=".xlsx,.csv,.txt" :disabled="$page.props.demo_uploads_off" @change="(e) => { form.file = e.target.files[0] ?? null; }" />
                    <p v-if="$page.props.demo_uploads_off" class="mt-1 text-sm font-semibold text-amber-800">Nella demo il caricamento dei file è spento.</p>
                    <span v-if="form.errors.file" class="block text-danger" role="alert">{{ form.errors.file }}</span>
                </label>
                <label class="space-y-1"><span class="block font-semibold">Conto per le righe senza conto</span>
                    <select v-model="form.default_account_id" :class="field"><option value="">nessuno: quelle righe danno errore</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                </label>
                <div class="sm:col-span-2"><button type="submit" class="btn btn-secondary" :disabled="!form.file || form.processing">Mostra l'anteprima</button></div>
            </form>
        </section>

        <section v-if="preview" class="mt-6 card p-4 sm:p-5" aria-labelledby="preview-title">
            <h2 id="preview-title" class="mb-2 text-lg font-bold">Anteprima</h2>
            <p class="mb-1 text-sm">
                {{ preview.summary.counts.create }} {{ preview.summary.counts.create === 1 ? 'movimento nuovo' : 'movimenti nuovi' }} · {{ preview.summary.counts.transfer }} {{ preview.summary.counts.transfer === 1 ? 'giroconto' : 'giroconti' }} · {{ preview.summary.counts.duplicate }} già {{ preview.summary.counts.duplicate === 1 ? 'presente' : 'presenti' }} · {{ preview.summary.counts.skip }} {{ preview.summary.counts.skip === 1 ? 'saltato' : 'saltati' }} · {{ preview.summary.counts.error }} {{ preview.summary.counts.error === 1 ? 'con errore' : 'con errori' }}
                <template v-if="preview.summary.from"> · dal {{ preview.summary.from.split('-').reverse().join('/') }} al {{ preview.summary.to.split('-').reverse().join('/') }}</template>
            </p>
            <p class="mb-3 text-sm">Entrate {{ euro(preview.summary.income) }} · uscite {{ euro(preview.summary.expense) }}<template v-if="preview.summary.new_accounts.length"> · conti nuovi: {{ preview.summary.new_accounts.join(', ') }}</template></p>
            <div class="max-h-[28rem] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-card"><tr class="text-muted"><th class="py-1 pr-3">Riga</th><th class="pr-3">Data</th><th class="pr-3">Descrizione</th><th class="pr-3 text-right">Importo</th><th class="pr-3">Esito</th><th>Note</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in preview.rows" :key="r.where">
                            <td class="py-1 pr-3 text-muted">{{ r.where }}</td><td class="whitespace-nowrap pr-3">{{ r.date }}</td><td class="pr-3">{{ r.description }}</td>
                            <td class="whitespace-nowrap pr-3 text-right tabular-nums">{{ r.amount }}</td>
                            <td class="pr-3"><span :class="['rounded px-2 py-0.5', badge[r.action]]">{{ r.label }}</span></td><td>{{ r.message }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <button type="button" class="mt-4 btn btn-primary" :disabled="form.processing || busy() || !(preview.summary.counts.create + preview.summary.counts.transfer)" @click="send(true)">
                {{ preview.summary.counts.create + preview.summary.counts.transfer === 1 ? 'Importa 1 movimento' : `Importa ${preview.summary.counts.create + preview.summary.counts.transfer} movimenti` }}
            </button>
            <p v-if="busy()" class="mt-2 text-sm text-muted">Aspetta che finisca l'importazione in corso.</p>
        </section>
    </AppLayout>
</template>
