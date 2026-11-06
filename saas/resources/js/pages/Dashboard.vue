<script setup>
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';
import EmptyState from '../components/ui/EmptyState.vue';
import { CalendarDays, Check, ChevronRight, CircleAlert, Info, Landmark, TriangleAlert } from '../components/ui/icons.js';

defineProps({
    greeting: { type: String, required: true },
    todo: { type: Array, default: () => [] },
    today: { type: Array, default: () => [] },
    stats: { type: Array, default: () => [] },
    peopleCount: { type: Number, required: true },
    setupPending: { type: Boolean, default: false },
    canSetup: { type: Boolean, default: false },
});

const icon = { danger: CircleAlert, warn: TriangleAlert, info: Info, ok: Check };
const tint = { danger: 'bg-danger-soft text-danger', warn: 'bg-warn-soft text-warn-ink', info: 'bg-info-soft text-info-ink', ok: 'bg-ok-soft text-ok-ink' };
const dateLabel = new Intl.DateTimeFormat('it-IT', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date());
</script>

<template>
    <Head title="Home" />
    <AppLayout>
        <header class="mb-5">
            <h1 class="page-title">{{ greeting }}</h1>
            <p class="mt-1 text-muted first-letter:uppercase">{{ dateLabel }}</p>
        </header>

        <section v-if="setupPending" class="card mb-5 flex flex-wrap items-center gap-4 border-accent/40 bg-info-soft p-4 sm:p-5" aria-labelledby="setup-title">
            <div class="min-w-0 flex-1">
                <h2 id="setup-title" class="section-title">Completa la configurazione</h2>
                <p class="mt-0.5 text-sm text-muted">Dati dell'ente, anno sociale e quota, conti e domande online: pochi minuti, tutto in una pagina.</p>
            </div>
            <Link href="/gestionale/configurazione" class="btn btn-primary w-full sm:w-auto">Apri la configurazione</Link>
        </section>

        <div class="grid gap-6 lg:grid-cols-5">
            <div class="space-y-6 lg:col-span-3">
                <section aria-labelledby="todo-title">
                    <h2 id="todo-title" class="section-title mb-2">Da fare</h2>
                    <ul v-if="todo.length" class="list-group">
                        <li v-for="t in todo" :key="t.title">
                            <Link :href="t.href" class="list-row">
                                <span :class="['grid size-10 shrink-0 place-items-center rounded-full', tint[t.tone] ?? tint.info]"><component :is="icon[t.tone] ?? Info" class="size-5" aria-hidden="true" /></span>
                                <span class="min-w-0 flex-1"><span class="block font-semibold">{{ t.title }}</span><span v-if="t.detail" class="caption block">{{ t.detail }}</span></span>
                                <ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" />
                            </Link>
                        </li>
                    </ul>
                    <div v-else class="card">
                        <EmptyState title="Tutto a posto" text="Non c'è niente che aspetti una tua risposta.">
                            <template #icon><Check class="size-6" /></template>
                        </EmptyState>
                    </div>
                </section>

                <section aria-labelledby="today-title">
                    <h2 id="today-title" class="section-title mb-2">Oggi</h2>
                    <ul v-if="today.length" class="list-group">
                        <li v-for="(t, i) in today" :key="i">
                            <component :is="t.href ? Link : 'div'" v-bind="t.href ? { href: t.href } : {}" class="list-row">
                                <span class="w-14 shrink-0 text-[15px] font-bold tabular-nums">{{ t.time && t.time !== '00:00' ? t.time : 'Oggi' }}</span>
                                <span class="min-w-0 flex-1"><span class="block truncate font-semibold">{{ t.title }}</span><span v-if="t.detail" class="caption block truncate">{{ t.detail }}</span></span>
                                <ChevronRight v-if="t.href" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                            </component>
                        </li>
                    </ul>
                    <div v-else class="card">
                        <EmptyState title="Oggi non c'è niente in programma" text="Lezioni, eventi e prenotazioni di oggi compaiono qui.">
                            <template #icon><CalendarDays class="size-6" /></template>
                        </EmptyState>
                    </div>
                </section>
            </div>

            <div class="space-y-6 lg:col-span-2">
                <section v-if="stats.length" aria-labelledby="money-title">
                    <h2 id="money-title" class="section-title mb-2">Conti</h2>
                    <ul class="list-group">
                        <li v-for="s in stats" :key="s.title">
                            <Link :href="s.href" class="list-row">
                                <Landmark class="size-5 shrink-0 text-muted" aria-hidden="true" />
                                <span class="min-w-0 flex-1 truncate font-semibold">{{ s.title }}</span>
                                <span class="font-bold tabular-nums">{{ s.detail }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section aria-labelledby="people-title">
                    <h2 id="people-title" class="sr-only">Anagrafica</h2>
                    <Link href="/gestionale/soci" class="card flex items-center gap-3 p-4 hover:bg-surface">
                        <span class="text-3xl font-bold tabular-nums">{{ peopleCount }}</span>
                        <span class="min-w-0 flex-1 text-muted">schede in anagrafica</span>
                        <ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    </Link>
                </section>

                <p v-if="canSetup" class="flex flex-wrap gap-x-5 gap-y-1 text-sm font-semibold">
                    <Link v-if="!setupPending" href="/gestionale/configurazione" class="text-accent hover:underline">Riapri la configurazione guidata</Link>
                    <Link href="/gestionale/esportazione" class="text-accent hover:underline">Esportazione completa dei dati</Link>
                </p>
            </div>
        </div>
    </AppLayout>
</template>
