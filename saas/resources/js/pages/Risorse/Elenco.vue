<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

defineProps({ archived: Boolean, limits: Array, rows: Array, canEdit: Boolean });
</script>

<template>
    <Head title="Risorse" />
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Risorse</h1>
                <p class="text-sm text-muted">Campi, sale, posti barca e postazioni: si prenotano a ore, a giornata o per una stagione.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <Link href="/gestionale/risorse/calendario" class="rounded-lg border border-line px-4 py-2 text-base font-semibold">Calendario</Link>
                <Link v-if="canEdit" href="/gestionale/risorse/nuova" class="rounded-lg bg-accent px-4 py-2 text-base font-semibold text-accent-ink">Nuova risorsa</Link>
            </div>
        </div>

        <section class="mb-6 grid gap-3 sm:grid-cols-3" aria-label="Risorse del pacchetto">
            <div v-for="l in limits" :key="l.value" class="rounded-2xl border border-line bg-card p-4">
                <p class="text-sm text-muted">{{ l.label }}</p>
                <p class="text-xl font-bold tabular-nums">{{ l.used }}<span v-if="l.tier !== null" class="text-base font-normal text-muted"> su {{ l.tier }}</span></p>
                <p v-if="l.tier === null" class="text-xs text-muted">non comprese nel pacchetto</p>
            </div>
        </section>

        <div class="overflow-x-auto rounded-2xl border border-line bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Risorsa</th>
                        <th class="px-4 py-3 font-semibold">Si prenota</th>
                        <th class="px-4 py-3 font-semibold">Tariffa</th>
                        <th class="px-4 py-3 font-semibold">Oggi</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="4" class="px-4 py-6 text-center text-muted">{{ archived ? 'Nessuna risorsa archiviata.' : 'Nessuna risorsa: aggiungi la prima.' }}</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line">
                        <td class="px-4 py-3">
                            <Link :href="`/gestionale/risorse/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link>
                            <span class="ml-2 text-xs text-muted">{{ r.category }}</span>
                        </td>
                        <td class="px-4 py-3">{{ r.typology }}</td>
                        <td class="whitespace-nowrap px-4 py-3 tabular-nums">{{ r.fee }}</td>
                        <td class="px-4 py-3 text-muted">{{ r.today }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-sm">
            <button type="button" class="text-accent hover:underline" @click="router.get('/gestionale/risorse', archived ? {} : { archiviate: 1 })">{{ archived ? 'Torna alle risorse attive' : 'Vedi le archiviate' }}</button>
        </p>
    </AppLayout>
</template>
