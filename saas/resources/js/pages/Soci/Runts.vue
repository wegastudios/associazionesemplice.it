<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ year: Number, years: Array, counts: Object, deadline: String, overdue: Boolean, min: Object });
const go = (y) => router.get('/gestionale/soci/runts', { anno: y });
const tiles = () => [
    ['Soci persone fisiche con diritto di voto', props.counts.persons, 'Maggiorenni alla data, tipo di socio con diritto di voto'],
    ['Enti soci con diritto di voto', props.counts.entities, `${props.counts.entities_same_section} della stessa sezione del Registro`],
    ['Volontari iscritti', props.counts.volunteers, 'Soci con tipo «socio e volontario»'],
    ['Lavoratori', props.counts.workers, 'Utenti con ruolo Dipendente: controlla che sia il numero giusto'],
];
</script>

<template>
    <Head title="Dati per il RUNTS" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="text-accent hover:underline">← Soci</Link></p>
        <div class="mb-4 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Dati per il RUNTS al 31 dicembre {{ year }}</h1>
                <p class="text-sm text-muted">I numeri da comunicare al Registro unico del Terzo settore entro il {{ deadline }}. Si calcolano da soli dall'anagrafica.</p>
            </div>
            <label class="space-y-1 text-sm"><span class="block font-semibold">Anno</span>
                <select class="field" :value="year" @change="go($event.target.value)"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select></label>
        </div>

        <p v-if="overdue" class="mb-4 rounded border border-line bg-surface p-3 text-sm" role="status">La scadenza del {{ deadline }} è passata: se non hai ancora comunicato questi dati, fallo appena puoi.</p>
        <p v-if="counts.below_minimum" class="mb-4 rounded border border-line bg-surface p-3 text-sm" role="alert">Una {{ counts.kind === 'odv' ? 'ODV' : 'APS' }} deve avere almeno {{ min.persons }} soci persone fisiche oppure {{ min.entities }} enti soci della stessa sezione: al 31 dicembre {{ year }} ne risultano {{ counts.persons }} e {{ counts.entities_same_section }}.</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <section v-for="t in tiles()" :key="t[0]" class="card p-4 sm:p-5">
                <p class="text-sm font-semibold text-muted">{{ t[0] }}</p>
                <p class="text-3xl font-bold tabular-nums">{{ t[1] }}</p>
                <p class="text-xs text-muted">{{ t[2] }}</p>
            </section>
        </div>

        <section v-if="counts.entity_list.length" class="mt-6 card p-4 sm:p-5">
            <h2 class="mb-3 text-lg font-bold">Enti soci</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="e in counts.entity_list" :key="e.id" class="flex justify-between gap-3 py-2">
                    <Link :href="`/gestionale/soci/${e.id}`" class="text-accent hover:underline">{{ e.name }}</Link>
                    <span class="text-muted">{{ e.same_section === true ? 'Stessa sezione' : e.same_section === false ? 'Altra sezione' : 'Sezione non indicata' }}</span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
