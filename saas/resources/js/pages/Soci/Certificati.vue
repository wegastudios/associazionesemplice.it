<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ filter: String, rows: Array, warnDays: Number });
const filters = [['', 'Tutti'], ['expiring', 'In scadenza'], ['expired', 'Scaduti'], ['none', 'Mancanti'], ['valid', 'Validi']];
const badge = { valid: 'bg-salvia/15', expiring: 'bg-giallo/25', expired: 'bg-danger/15 text-danger', none: 'bg-surface' };
</script>

<template>
    <Head title="Certificati medici" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="font-semibold text-accent hover:underline">← Soci</Link></p>
        <h1 class="mb-1 text-2xl font-bold">Certificati medici</h1>
        <p class="mb-6 text-sm text-muted">Scadenze dei soci; «in scadenza» vuol dire entro {{ warnDays }} giorni. Qui non si aprono i dati sanitari.</p>
        <div class="mb-4 flex flex-wrap gap-2 text-sm font-semibold">
            <button v-for="f in filters" :key="f[0]" type="button" class="rounded-lg border border-line px-3 py-1.5"
                :class="filter === f[0] ? 'bg-accent text-accent-ink' : 'bg-card'" @click="router.get('/gestionale/certificati', { stato: f[0] })">{{ f[1] }}</button>
        </div>
        <div class="card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted"><tr><th class="px-4 py-3">Tessera</th><th class="px-4 py-3">Socio</th><th class="px-4 py-3">Scadenza</th><th class="px-4 py-3">Stato</th></tr></thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="4" class="px-4 py-6 text-center text-muted">Nessun socio.</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line">
                        <td class="px-4 py-3 tabular-nums">{{ r.card ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold"><Link :href="`/gestionale/soci/${r.id}`" class="text-accent hover:underline">{{ r.name }}</Link></td>
                        <td class="px-4 py-3">{{ r.expires_on ?? '—' }}</td>
                        <td class="px-4 py-3"><span class="rounded px-2 py-0.5 font-semibold" :class="badge[r.status]">{{ r.label }}</span></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
