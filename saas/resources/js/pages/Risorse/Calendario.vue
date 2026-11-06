<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ date: String, previous: String, next: String, days: Array, dayLabel: String, hourly: Array, daily: Array, seasonal: Array });
const go = (d) => router.get('/gestionale/risorse/calendario', { giorno: d }, { preserveScroll: true });
const cell = (c) => (c.busy ? (c.block ? 'bg-muted/20 text-muted' : 'bg-accent/15') : 'bg-salvia/10');
const field = 'field w-auto';
</script>

<template>
    <Head title="Calendario delle risorse" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/risorse" class="text-accent hover:underline">← Risorse</Link></p>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Calendario</h1>
                <p class="text-sm text-muted">Occupazione di {{ dayLabel }}. Per prenotare apri la risorsa.</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <button type="button" class="field w-auto" aria-label="Giorno prima" @click="go(previous)">←</button>
                <input type="date" :value="date" :class="field" aria-label="Giorno" @change="go($event.target.value)" />
                <button type="button" class="field w-auto" aria-label="Giorno dopo" @click="go(next)">→</button>
            </div>
        </div>

        <p v-if="!hourly.length && !daily.length && !seasonal.length" class="card p-5 sm:p-6 text-center text-muted">Nessuna risorsa attiva.</p>

        <section v-if="hourly.length" class="mb-6 card p-4 sm:p-5" aria-labelledby="h-title">
            <h2 id="h-title" class="mb-3 text-lg font-bold">A ore</h2>
            <div v-for="r in hourly" :key="r.id" class="mb-4">
                <Link :href="`/gestionale/risorse/${r.id}?giorno=${date}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link>
                <div class="mt-2 flex flex-wrap gap-1">
                    <span v-for="s in r.slots" :key="s.start" :class="['min-w-24 rounded px-2 py-1 text-xs', cell(s)]" :title="s.label || 'libera'">
                        <span class="font-semibold tabular-nums">{{ s.start }}</span> <span class="block truncate">{{ s.label || 'libera' }}</span>
                    </span>
                </div>
            </div>
        </section>

        <section v-if="daily.length" class="mb-6 card overflow-x-auto p-5" aria-labelledby="d-title">
            <h2 id="d-title" class="mb-3 text-lg font-bold">A giornata, nei prossimi 14 giorni</h2>
            <table class="w-full text-xs">
                <thead><tr><th class="py-1 pr-2 text-left font-semibold">Risorsa</th><th v-for="d in days" :key="d.date" class="px-1 py-1 font-semibold text-muted">{{ d.label }}</th></tr></thead>
                <tbody>
                    <tr v-for="r in daily" :key="r.id" class="border-t border-line">
                        <td class="py-2 pr-2"><Link :href="`/gestionale/risorse/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link></td>
                        <td v-for="c in r.days" :key="c.date" class="p-0.5"><span :class="['block truncate rounded px-1 py-1', cell(c)]" :title="c.label || 'libera'">{{ c.label || '·' }}</span></td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="seasonal.length" class="card p-4 sm:p-5" aria-labelledby="s-title">
            <h2 id="s-title" class="mb-3 text-lg font-bold">A stagione</h2>
            <ul class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <li v-for="r in seasonal" :key="r.id" :class="['rounded-lg px-3 py-2', cell(r)]">
                    <Link :href="`/gestionale/risorse/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link>
                    <span class="block">{{ r.label }}<template v-if="r.until"> · {{ r.until }}</template></span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
