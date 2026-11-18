<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ types: Array, columns: Array, preview: Object, result: Object });

const form = useForm({ file: null, default_type: props.types[0].value, mark_current: false, confirm_years: false, apply: false });
const send = (apply) => {
    form.apply = apply;
    form.post('/gestionale/soci/importa', { forceFormData: true, preserveState: true, preserveScroll: true });
};
const badge = { create: 'bg-salvia/15', update: 'bg-surface', error: 'bg-danger/15 text-danger' };
const label = { create: 'nuova', update: 'aggiorna', error: 'errore' };
const field = 'field';
</script>

<template>
    <Head title="Importa soci" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="text-accent hover:underline">← Soci</Link></p>
        <h1 class="mb-4 text-2xl font-bold">Importa soci da Excel o CSV</h1>

        <section v-if="result" class="mb-6 card p-4 sm:p-5" role="status">
            <h2 class="mb-2 text-lg font-bold">Import completato</h2>
            <p>{{ result.created }} schede nuove, {{ result.updated }} aggiornate, {{ result.memberships }} tessere registrate, {{ result.guardians }} tutori collegati.</p>
            <template v-if="result.failed.length">
                <p class="mt-3 font-semibold text-danger">Righe non importate:</p>
                <ul class="list-disc pl-5 text-sm"><li v-for="f in result.failed" :key="f">{{ f }}</li></ul>
            </template>
            <p class="mt-3"><Link href="/gestionale/soci" class="font-semibold text-accent hover:underline">Vai all'elenco</Link></p>
        </section>

        <section class="card p-4 sm:p-5">
            <p class="mb-3 text-sm text-muted">Servono almeno le colonne <strong>Nome</strong> e <strong>Cognome</strong>, e per ogni persona l'email, il cellulare o il numero di tessera. Si riconoscono anche:</p>
            <p class="mb-4 text-sm">{{ columns.join(' · ') }}. <a href="/gestionale/soci/modello-import.csv" class="font-semibold text-accent hover:underline">Scarica il modello</a></p>
            <p class="mb-4 text-sm text-muted">Per i <strong>soci enti</strong> aggiungi la colonna «Tipo soggetto» (scrivi <em>ente</em>) e «Denominazione» al posto di nome e cognome, il codice fiscale dell'ente (11 cifre oppure 16 caratteri) e «Stessa sezione RUNTS» (sì o no). Un ospite non può essere un ente.</p>
            <p class="mb-4 text-sm text-muted">Chi è già in anagrafica (stessa tessera, email, cellulare, codice fiscale o nome) viene aggiornato con i soli dati presenti nel file. Prima vedi l'anteprima: nulla si salva finché non confermi.</p>
            <form class="grid gap-4 text-sm sm:grid-cols-2" @submit.prevent="send(false)">
                <label class="space-y-1 sm:col-span-2"><span class="block font-semibold">File (.xlsx o .csv, al massimo 5 MB)</span>
                    <input type="file" accept=".xlsx,.csv,.txt" :disabled="$page.props.demo_uploads_off" @change="(e) => { form.file = e.target.files[0] ?? null; }" />
                    <p v-if="$page.props.demo_uploads_off" class="mt-1 text-sm font-semibold text-amber-800">Nella demo il caricamento dei file è spento.</p>
                    <span v-if="form.errors.file" class="block text-danger" role="alert">{{ form.errors.file }}</span>
                </label>
                <label class="space-y-1"><span class="block font-semibold">Tipo se la colonna «Tipo» manca</span>
                    <select v-model="form.default_type" :class="field"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select>
                </label>
                <label class="flex items-center gap-2 self-end"><input v-model="form.mark_current" type="checkbox" /> Registra a tutti i soci la tessera dell'anno in corso (senza incasso)</label>
                <div class="sm:col-span-2"><button type="submit" class="btn btn-secondary" :disabled="!form.file || form.processing">Mostra l'anteprima</button></div>
            </form>
        </section>

        <section v-if="preview" class="mt-6 card p-4 sm:p-5" aria-labelledby="preview-title">
            <h2 id="preview-title" class="mb-2 text-lg font-bold">Anteprima</h2>
            <p class="mb-3 text-sm">{{ preview.create }} nuove · {{ preview.update }} da aggiornare · {{ preview.error }} con errori<template v-if="preview.sheets.length > 1"> · fogli: {{ preview.sheets.join(', ') }}</template></p>
            <div class="max-h-[28rem] overflow-auto">
                <table class="w-full text-left text-sm">
                    <thead class="sticky top-0 bg-card"><tr class="text-muted"><th class="py-1 pr-3">Riga</th><th class="pr-3">Nome</th><th class="pr-3">Tipo</th><th class="pr-3">Esito</th><th>Note</th></tr></thead>
                    <tbody class="divide-y divide-line">
                        <tr v-for="r in preview.rows" :key="r.where">
                            <td class="py-1 pr-3 text-muted">{{ r.where }}</td><td class="pr-3">{{ r.name }}</td><td class="pr-3">{{ r.type }}</td>
                            <td class="pr-3"><span :class="['rounded px-2 py-0.5', badge[r.action]]">{{ label[r.action] }}</span></td><td>{{ r.message }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <div v-if="preview.years" class="mt-4 rounded border border-line p-3 text-sm" role="status">
                <h3 class="font-semibold">Tessere con anno diverso dall'anno fiscale</h3>
                <p class="mt-1">{{ preview.years.count }} {{ preview.years.count === 1 ? 'tessera dell’elenco ha' : 'tessere dell’elenco hanno' }} un'etichetta che non corrisponde all'anno fiscale (per esempio «{{ preview.years.examples[0].split(' → ')[0] }}» con l'anno fiscale non da gennaio). Con la conferma passano all'anno fiscale che le contiene (per esempio «{{ preview.years.examples[0].split(' → ')[1] }}»). Nessun cambio avviene senza la tua conferma.</p>
                <ul class="mt-1 list-disc pl-5"><li v-for="e in preview.years.examples" :key="e">{{ e }}</li></ul>
                <label class="mt-2 flex items-start gap-2"><input v-model="form.confirm_years" type="checkbox" class="mt-1" /><span>Ho letto cosa succede alle tessere e voglio importarle così.</span></label>
            </div>
            <button type="button" class="mt-4 btn btn-primary" :disabled="form.processing || !(preview.create + preview.update) || (preview.years && !form.confirm_years)" @click="send(true)">
                Importa {{ preview.create + preview.update }} righe
            </button>
            <p v-if="preview.error" class="mt-2 text-sm text-muted">Le righe con errori si saltano: correggile nel file e ricaricalo quando vuoi.</p>
        </section>
    </AppLayout>
</template>
