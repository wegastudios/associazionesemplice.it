<script setup>
import { Head, router, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

defineProps({ csrf: String, canHealth: Boolean, years: Array, archives: Array });
const page = usePage();
const withCertificates = ref(false);
const archive = (y) => {
    if (window.confirm(`Archiviare l'anno fiscale ${y}? Le ricevute si raccolgono in un solo file cifrato e se ne controlla ogni impronta; solo dopo il controllo i file singoli vengono tolti. Si può tornare indietro con «Rimetti i file». L'operazione resta scritta nel registro azioni.`)) {
        router.post(`/gestionale/archivio/${y}`, {}, { preserveScroll: true });
    }
};
const restore = (y) => {
    if (window.confirm(`Rimettere i file singoli dell'anno fiscale ${y}? L'archivio viene aperto e controllato; i file tornano al loro posto e l'archivio viene tolto. L'operazione resta scritta nel registro azioni.`)) {
        router.post(`/gestionale/archivio/${y}/rimetti`, {}, { preserveScroll: true });
    }
};
const day = (d) => (d ? d.split('-').reverse().join('/') : '');
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
            <form method="post" action="/gestionale/esportazione.zip" class="space-y-3">
                <input type="hidden" name="_token" :value="csrf" />
                <label class="flex items-center gap-2"><span class="font-semibold">Documenti leggibili di</span>
                    <select name="anno" class="field w-auto"><option value="">tutti gli anni fiscali</option><option v-for="y in years" :key="y" :value="y">anno fiscale {{ y }}</option></select></label>
                <div v-if="canHealth">
                    <label class="flex items-center gap-2 font-semibold"><input v-model="withCertificates" type="checkbox" name="certificati" value="1">Includi i certificati medici (dati sulla salute) in chiaro</label>
                    <p class="mt-1 text-muted">Sono dati sulla salute, in chiaro, senza protezione: conservali con cura e consegnali solo a chi ne ha titolo.</p>
                </div>
                <p class="text-muted">Ti chiediamo la password prima di scaricare. Dopo il clic la copia viene preparata: può richiedere qualche minuto. Il download parte da solo: non chiudere la pagina.</p>
                <button type="submit" class="btn btn-primary">Scarica copia di sicurezza</button>
            </form>
        </section>
        <section class="card mt-6 p-4 text-sm sm:p-5">
            <h2 class="mb-1 text-lg font-bold">Archivio degli anni chiusi</h2>
            <p class="mb-3">Un anno fiscale chiuso si può archiviare: le sue ricevute in PDF si raccolgono in un solo file cifrato, conservato nel gestionale, al posto dei file singoli. I dati restano dove sono: l'archivio contiene solo file. Serve a tenere in ordine gli anni vecchi: per te non cambia nulla, le ricevute si scaricano come sempre. I certificati medici non vanno in archivio.</p>
            <p v-if="page.props.errors?.archive" class="mb-3 font-semibold text-danger" role="alert">{{ page.props.errors.archive }}</p>
            <table v-if="archives.length" class="w-full text-left">
                <tbody>
                    <tr v-for="a in archives" :key="a.year" class="border-t">
                        <td class="p-2 font-semibold">Anno fiscale {{ a.year }}</td>
                        <td class="p-2">{{ a.files }} ricevute</td>
                        <td class="p-2">
                            <template v-if="a.state === 'archived'">Archiviato il {{ day(a.archived_on) }}</template>
                            <template v-else-if="a.state === 'ready'">Da archiviare</template>
                            <span v-else class="text-muted">Non ancora: si archiviano solo gli anni fiscali prima dell'ultimo anno chiuso.</span>
                        </td>
                        <td class="p-2 text-right">
                            <button v-if="a.state === 'ready'" type="button" class="btn" @click="archive(a.year)">Archivia l'anno</button>
                            <button v-else-if="a.state === 'archived'" type="button" class="btn" @click="restore(a.year)">Rimetti i file</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted">Nessun anno fiscale chiuso.</p>
        </section>
    </AppLayout>
</template>
