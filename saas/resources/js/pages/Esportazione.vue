<script setup>
import { Head, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

defineProps({ canHealth: Boolean, years: Array });
const page = usePage();
const withCertificates = ref(false);
</script>

<template>
    <Head title="Copie di sicurezza" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Copie di sicurezza</h1>
        <p class="mb-6 text-sm text-muted">Tutti i dati dell'associazione in un archivio ZIP.</p>
        <section class="card p-4 sm:p-5 text-sm">
            <p class="mb-3">Contiene due parti: «Dati per il programma» (per riportare i dati nel plugin o nel gestionale Associazione Semplice) e «Documenti leggibili» (PDF e tabelle che si aprono senza programma, da consegnare).</p>
            <ul class="mb-4 list-disc space-y-1 pl-5">
                <li>Contiene dati personali: conservala con cura. Resta traccia di chi scarica la copia.</li>
                <li>La prima nota e l'anagrafica si possono ricaricare con gli import.</li>
            </ul>
            <p v-if="page.props.errors?.export" class="mb-3 font-semibold text-danger" role="alert">{{ page.props.errors.export }}</p>
            <form method="get" action="/gestionale/esportazione.zip" class="space-y-3">
                <label class="flex items-center gap-2"><span class="font-semibold">Documenti leggibili di</span>
                    <select name="anno" class="field w-auto"><option value="">tutti gli anni fiscali</option><option v-for="y in years" :key="y" :value="y">anno fiscale {{ y }}</option></select></label>
                <div v-if="canHealth">
                    <label class="flex items-center gap-2 font-semibold"><input v-model="withCertificates" type="checkbox" name="certificati" value="1">Includi i certificati medici (dati sulla salute) in chiaro</label>
                    <p class="mt-1 text-muted">Sono dati sulla salute, in chiaro, senza protezione: conservali con cura e consegnali solo a chi ne ha titolo.</p>
                </div>
                <p class="text-muted">Dopo il clic la copia viene preparata: può richiedere qualche minuto. Il download parte da solo: non chiudere la pagina.</p>
                <button type="submit" class="btn btn-primary">Scarica copia di sicurezza</button>
            </form>
        </section>
    </AppLayout>
</template>
