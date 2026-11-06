<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

defineProps({ from: String, previous: String, next: String, days: Array });
const go = (d) => router.get('/gestionale/calendario', { dal: d }, { preserveScroll: true });
</script>

<template>
    <Head title="Calendario delle attività" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/attivita" class="text-accent hover:underline">← Attività</Link></p>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Calendario</h1>
                <p class="text-sm text-muted">Lezioni dei corsi, eventi e date con prenotazione, per sette giorni.</p>
            </div>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" class="field w-auto" aria-label="Settimana prima" @click="go(previous)">←</button>
                <input type="date" :value="from" class="field w-auto" aria-label="Dal giorno" @change="go($event.target.value)" />
                <button type="button" class="field w-auto" aria-label="Settimana dopo" @click="go(next)">→</button>
            </div>
        </div>
        <div class="space-y-3">
            <section v-for="d in days" :key="d.date" class="card p-4" :aria-label="d.label">
                <h2 class="mb-2 font-bold first-letter:uppercase">{{ d.label }}</h2>
                <p v-if="!d.items.length" class="text-sm text-muted">Niente in programma.</p>
                <ul class="space-y-1 text-sm">
                    <li v-for="(x, i) in d.items" :key="i" class="flex flex-wrap items-center gap-3">
                        <span class="w-24 font-semibold tabular-nums">{{ x.start ?? '' }}<template v-if="x.end">–{{ x.end }}</template></span>
                        <Link :href="x.session_id ? `/gestionale/attivita/date/${x.session_id}` : `/gestionale/attivita/${x.activity_id}`" class="font-semibold text-accent hover:underline">{{ x.title }}</Link>
                        <span class="text-muted">{{ x.kind }}<template v-if="x.location"> · {{ x.location }}</template></span>
                        <span v-if="x.taken !== null" class="rounded bg-surface px-2 py-0.5 text-xs">{{ x.taken }} prenotati<template v-if="x.capacity"> su {{ x.capacity }}</template></span>
                        <Link :href="`/gestionale/attivita/${x.activity_id}/presenze?mese=${d.date.slice(0, 7)}&giorno=${d.date}`" class="text-xs text-accent hover:underline">presenze</Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
