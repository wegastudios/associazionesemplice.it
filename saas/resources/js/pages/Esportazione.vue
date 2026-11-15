<script setup>
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

defineProps({ canHealth: Boolean });
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
            <form method="get" action="/gestionale/esportazione.zip" class="space-y-3">
                <div v-if="canHealth">
                    <label class="flex items-center gap-2 font-semibold"><input v-model="withCertificates" type="checkbox" name="certificati" value="1">Includi i certificati medici (dati sulla salute) in chiaro</label>
                    <p class="mt-1 text-muted">Sono dati sulla salute, in chiaro, senza protezione: conservali con cura e consegnali solo a chi ne ha titolo.</p>
                </div>
                <p class="text-muted">La copia è in preparazione dopo il clic: può richiedere qualche minuto. Quando è pronta il download parte da solo.</p>
                <button type="submit" class="btn btn-primary">Scarica copia di sicurezza</button>
            </form>
        </section>
    </AppLayout>
</template>
