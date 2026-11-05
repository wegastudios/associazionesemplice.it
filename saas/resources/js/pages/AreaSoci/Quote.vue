<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';

defineProps({ details: Object, enrollments: Array, receipts: Array, member: Object });
</script>

<template>
    <Head title="Quote e ricevute" />
    <MemberLayout>
        <div class="mb-4 flex flex-wrap items-center justify-between gap-3">
            <h1 class="text-2xl font-bold">Quote e ricevute</h1>
            <Link href="/area/paga" class="rounded-lg bg-accent px-4 py-2 text-sm font-semibold text-accent-ink">Paga online</Link>
        </div>
        <section class="mb-6 rounded-2xl border border-line bg-card p-5" aria-labelledby="a">
            <h2 id="a" class="mb-3 text-lg font-bold">Corsi e attività</h2>
            <p v-if="!enrollments.length" class="text-sm text-muted">Nessuna iscrizione.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="(e, i) in enrollments" :key="i" class="py-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="font-semibold">{{ e.activity }}</span><span class="text-muted">{{ e.kind }} · dal {{ e.from }}<template v-if="e.to"> al {{ e.to }}</template></span>
                        <span class="ml-auto rounded px-2 py-0.5" :class="e.regular ? 'bg-salvia/15' : 'bg-danger/15 text-danger'">{{ e.regular ? 'in regola' : 'da versare ' + e.missing }}</span>
                    </div>
                    <p class="mt-1 text-xs text-muted">Dovuto finora {{ e.due_label }} · versato {{ e.paid_label }}</p>
                </li>
            </ul>
        </section>
        <section class="rounded-2xl border border-line bg-card p-5" aria-labelledby="r">
            <h2 id="r" class="mb-3 text-lg font-bold">Ricevute</h2>
            <p v-if="!receipts.length" class="text-sm text-muted">Nessuna ricevuta.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="r in receipts" :key="r.id" class="flex flex-wrap items-center gap-3 py-3">
                    <a :href="`/area/ricevute/${r.id}.pdf`" target="_blank" class="font-semibold text-accent hover:underline">Ricevuta n. {{ r.number }}</a>
                    <span class="text-muted">{{ r.date }}</span>
                    <span class="min-w-0 flex-1 truncate text-muted">{{ r.description }}</span>
                    <span class="font-semibold tabular-nums">{{ r.total_label }}</span>
                </li>
            </ul>
        </section>
    </MemberLayout>
</template>
