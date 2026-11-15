<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ year: Number, years: Array, rows: Array, unfixed: Number });
const page = usePage();
const go = (y) => router.get('/gestionale/verifica-ricevute', { anno: y }, { preserveState: true });
const fix = () => router.post('/gestionale/verifica-ricevute/fissa', {}, { preserveScroll: true });
const rebuild = (r) => {
    if (window.confirm('Rifare questo PDF dai dati salvati all\'emissione? Un file diverso resta messo da parte e l\'operazione viene scritta nel registro azioni.')) {
        router.post(`/gestionale/verifica-ricevute/${r.id}/rifai`, {}, { preserveScroll: true });
    }
};
const bad = () => props.rows.some((r) => r.status === 'missing' || r.status === 'altered');
</script>

<template>
    <Head title="Verifica delle ricevute" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Verifica delle ricevute</h1>
        <p class="mb-4 text-sm text-muted">Il PDF di ogni ricevuta si crea una volta sola, all'emissione, e si salva con la sua impronta: si scarica e si spedisce sempre lo stesso file.</p>
        <p v-if="page.props.errors?.ledger" class="mb-3 font-semibold text-danger" role="alert">{{ page.props.errors.ledger }}</p>
        <p v-if="bad()" class="mb-3 font-semibold text-danger" role="alert">Alcuni PDF non coincidono con quelli emessi. Finché non li sistemi, scaricare quelle ricevute dà un errore (non vengono mai rifatte di nascosto). Puoi ripristinare il file da una copia di sicurezza oppure rifarlo dai dati salvati: lo si fa solo se i dati sono intatti e l'operazione resta scritta nel registro azioni.</p>
        <div class="card mb-4 space-y-3 p-4 text-sm sm:p-5">
            <label class="flex items-center gap-2"><span class="font-semibold">Esercizio</span>
                <select class="field w-auto" :value="year" @change="go($event.target.value)"><option v-for="y in years" :key="y.year" :value="y.year">{{ y.year }}</option></select></label>
            <div v-if="unfixed > 0" class="flex flex-wrap items-center gap-3"><span>{{ unfixed }} ricevute emesse prima di questo aggiornamento non hanno ancora il PDF fissato.</span>
                <button type="button" class="btn btn-primary" @click="fix">Fissa i prossimi PDF</button></div>
            <p class="text-muted">Per le ricevute emesse prima di questo aggiornamento il PDF usa i testi di oggi e i dati di oggi; quelle emesse da ora in poi restano identiche per sempre.</p>
        </div>
        <div class="card overflow-x-auto p-0">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="p-3">Ricevuta</th><th class="p-3">Data</th><th class="p-3">Stato</th><th class="p-3"></th></tr></thead>
                <tbody>
                    <tr v-for="r in rows" :key="r.id" class="border-t">
                        <td class="p-3 font-semibold">n. {{ r.number }}</td><td class="p-3">{{ r.date }}</td>
                        <td class="p-3" :class="r.status === 'missing' || r.status === 'altered' ? 'font-semibold text-danger' : ''">{{ r.label }}</td>
                        <td class="p-3"><button v-if="r.status === 'missing' || r.status === 'altered'" type="button" class="btn" @click="rebuild(r)">Rifai dai dati salvati</button></td>
                    </tr>
                    <tr v-if="!rows.length"><td class="p-3 text-muted" colspan="4">Nessuna ricevuta in questo esercizio.</td></tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
