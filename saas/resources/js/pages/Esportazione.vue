<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
</script>

<template>
    <Head title="Esportazione completa" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Esportazione completa dei dati</h1>
        <p class="mb-6 text-sm text-muted">Tutti i dati dell'associazione in un archivio ZIP: anagrafica e libro soci, tessere, tutori, consensi, domande, certificati medici, attività, prima nota, conti, ricevute, operatori, impostazioni e registro delle azioni. Un file CSV per tabella, da aprire con Excel.</p>
        <section class="card p-4 sm:p-5 text-sm">
            <ul class="mb-4 list-disc space-y-1 pl-5">
                <li>La prima nota e l'anagrafica si possono ricaricare con gli import.</li>
                <li>Contiene dati personali e sanitari: conservalo con cura e cancellalo quando non serve più. Lo scarico resta nel registro delle azioni.</li>
                <li>I file allegati ai certificati medici non sono compresi: si scaricano dalla scheda della persona.</li>
            </ul>
            <a href="/gestionale/esportazione.zip" class="inline-block btn btn-primary">Scarica l'archivio</a>
        </section>
    </AppLayout>
</template>
