<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

defineProps({
    peopleCount: { type: Number, required: true },
    setupPending: { type: Boolean, default: false },
    canSetup: { type: Boolean, default: false },
});
</script>

<template>
    <Head title="Gestionale" />
    <AppLayout>
        <h1 class="mb-6 text-2xl font-bold">Gestionale</h1>
        <section v-if="setupPending" class="mb-6 flex flex-wrap items-center gap-4 rounded-2xl border border-accent/40 bg-accent/5 p-5">
            <div class="flex-1">
                <h2 class="font-bold">Completa la configurazione</h2>
                <p class="text-sm text-muted">Dati dell'ente, anno sociale e quota, conti e domande online: pochi minuti, tutto in una pagina.</p>
            </div>
            <Link href="/gestionale/configurazione" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink">Apri la configurazione</Link>
        </section>
        <div class="grid gap-4 sm:grid-cols-3">
            <section class="rounded-2xl border border-line bg-card p-5">
                <h2 class="text-sm font-semibold text-muted">Schede in anagrafica</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums">{{ peopleCount }}</p>
            </section>
        </div>
        <p v-if="canSetup" class="mt-6 flex flex-wrap gap-4 text-sm font-semibold">
            <Link v-if="!setupPending" href="/gestionale/configurazione" class="text-accent hover:underline">Riapri la configurazione guidata</Link>
            <Link href="/gestionale/esportazione" class="text-accent hover:underline">Esportazione completa dei dati</Link>
        </p>
    </AppLayout>
</template>
