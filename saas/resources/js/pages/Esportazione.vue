<script setup>
import { Head, router, usePage, usePoll } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({ csrf: String, canHealth: Boolean, years: Array, archives: Array, backup: Object });
const page = usePage();
const withCertificates = ref(false);
// Copia in coda o in preparazione: la pagina rilegge lo stato ogni 5 secondi finché non è pronta.
const working = computed(() => ['queued', 'running'].includes(props.backup?.status));
const { start, stop } = usePoll(5000, { only: ['backup'] }, { autoStart: false });
watch(working, (w) => (w ? start() : stop()), { immediate: true });
const when = (t) => (t ? new Date(t).toLocaleString('it-IT', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' }) : '');
const archive = (y) => {
    if (window.confirm(`Archiviare l'anno fiscale ${y}? Le ricevute vengono raccolte in un solo file cifrato e controllate una per una; solo dopo i file singoli vengono tolti. Puoi tornare indietro con «Rimetti i file». L'operazione resta scritta nel registro azioni.`)) {
        router.post(`/gestionale/archivio/${y}`, {}, { preserveScroll: true });
    }
};
const restore = (y) => {
    if (window.confirm(`Rimettere i file singoli dell'anno fiscale ${y}? L'archivio viene aperto e controllato; i file tornano al loro posto e l'archivio viene tolto. L'operazione resta scritta nel registro azioni.`)) {
        router.post(`/gestionale/archivio/${y}/rimetti`, {}, { preserveScroll: true });
    }
};
const rebuild = (y) => {
    if (window.confirm(`Ricostruire le ricevute dell'anno fiscale ${y} dai dati salvati quando sono state emesse? Si rifanno i PDF, i file singoli tornano al loro posto e l'archivio rovinato viene tolto. L'operazione resta scritta nel registro azioni.`)) {
        router.post(`/gestionale/archivio/${y}/ricostruisci`, {}, { preserveScroll: true });
    }
};
const day = (d) => (d ? d.split('-').reverse().join('/') : '');
</script>

<template>
    <Head title="Copie di sicurezza" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Copie di sicurezza</h1>
        <p class="mb-6 text-sm text-muted">Tutti i dati dell'associazione in un solo file ZIP.</p>
        <section class="card p-4 sm:p-5 text-sm">
            <p class="mb-3">Contiene due parti: «Dati per il programma» (per riportare i dati nel plugin o nel gestionale Associazione Semplice) e «Documenti leggibili» (PDF e tabelle che si aprono senza programma, da consegnare).</p>
            <ul class="mb-4 list-disc space-y-1 pl-5">
                <li>Contiene dati personali: conservala con cura. Resta traccia di chi scarica la copia.</li>
                <li>La prima nota e l'anagrafica si possono ricaricare con «Importa da Excel o CSV», nelle pagine Prima nota e Soci.</li>
            </ul>
            <p v-if="page.props.errors?.export" class="mb-3 font-semibold text-danger" role="alert">{{ page.props.errors.export }}</p>
            <form method="post" action="/gestionale/esportazione.zip" class="space-y-3">
                <input type="hidden" name="_token" :value="csrf" />
                <label class="flex items-center gap-2"><span class="font-semibold">Documenti leggibili di</span>
                    <select name="anno" class="field w-auto"><option value="">tutti gli anni fiscali</option><option v-for="y in years" :key="y" :value="y">anno fiscale {{ y }}</option></select></label>
                <div v-if="canHealth">
                    <label class="flex items-center gap-2 font-semibold"><input v-model="withCertificates" type="checkbox" name="certificati" value="1">Includi i certificati medici</label>
                    <p class="mt-1 text-muted">Sono dati sulla salute e nella copia non sono protetti: conservali con cura e consegnali solo a chi ne ha titolo.</p>
                </div>
                <p class="text-muted">Ti viene chiesta la password, poi la copia si prepara mentre fai altro: puoi chiudere la pagina e tornare qui a scaricarla quando è pronta.</p>
                <button type="submit" class="btn btn-primary" :disabled="working">Prepara la copia di sicurezza</button>
            </form>
            <div v-if="backup" class="mt-4 border-t pt-4" role="status" aria-live="polite">
                <p v-if="backup.status === 'queued'">La copia è in coda: la preparazione parte entro un paio di minuti.</p>
                <p v-else-if="backup.status === 'running'">La copia è in preparazione<template v-if="backup.total">: {{ backup.done }} file su {{ backup.total }}</template>. Puoi chiudere la pagina e tornare più tardi.</p>
                <template v-else-if="backup.status === 'ready' && backup.url">
                    <p class="mb-2">La copia del {{ when(backup.created_at) }} è pronta ({{ backup.files }} file<template v-if="backup.year">, documenti leggibili dell'anno fiscale {{ backup.year }}</template><template v-if="backup.with_certificates">, con i certificati medici</template>). Si può scaricare fino al {{ when(backup.expires_at) }}, poi viene cancellata.</p>
                    <a :href="backup.url" class="btn btn-primary">Scarica la copia di sicurezza</a>
                </template>
                <p v-else-if="backup.status === 'ready'" class="text-muted">La copia del {{ when(backup.created_at) }} è scaduta ed è stata cancellata: se ti serve, preparane una nuova.</p>
                <p v-else-if="backup.status === 'failed'" class="font-semibold text-danger" role="alert">{{ backup.error }}</p>
            </div>
        </section>
        <section class="card mt-6 p-4 text-sm sm:p-5">
            <h2 class="mb-1 text-lg font-bold">Archivio degli anni chiusi</h2>
            <p class="mb-3">Puoi archiviare un anno fiscale chiuso: le sue ricevute in PDF vengono raccolte in un solo file cifrato, conservato nel gestionale. Per te non cambia nulla: movimenti e ricevute restano dove sono e si scaricano come sempre. I certificati medici non vanno in archivio.</p>
            <p v-if="page.props.errors?.archive" class="mb-3 font-semibold text-danger" role="alert">{{ page.props.errors.archive }}</p>
            <table v-if="archives.length" class="w-full text-left">
                <tbody>
                    <tr v-for="a in archives" :key="a.year" class="border-t">
                        <td class="p-2 font-semibold">Anno fiscale {{ a.year }}</td>
                        <td class="p-2">{{ a.files === 1 ? '1 ricevuta' : `${a.files} ricevute` }}</td>
                        <td class="p-2">
                            <template v-if="a.state === 'archived'">Archiviato il {{ day(a.archived_on) }}</template>
                            <template v-else-if="a.state === 'ready'">Da archiviare</template>
                            <span v-else class="text-muted">Non ancora: si archiviano solo gli anni fiscali prima dell'ultimo anno chiuso.</span>
                        </td>
                        <td class="p-2 text-right">
                            <button v-if="a.state === 'ready'" type="button" class="btn" @click="archive(a.year)">Archivia l'anno</button>
                            <button v-else-if="a.state === 'archived'" type="button" class="btn" @click="restore(a.year)">Rimetti i file</button>
                            <button v-if="a.state === 'archived' && page.props.errors?.archive_recover === String(a.year)" type="button" class="btn ml-2" @click="rebuild(a.year)">Ricostruisci dai dati dell'emissione</button>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p v-else class="text-muted">Nessun anno fiscale chiuso.</p>
        </section>
    </AppLayout>
</template>
