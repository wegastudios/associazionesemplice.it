<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({ orgName: String, card: Object });
</script>

<template>
    <Head title="Verifica della tessera" />
    <main class="flex min-h-full items-center justify-center px-4 py-12">
        <section class="w-full max-w-sm rounded-2xl border border-line bg-card p-6 text-center shadow-sm" aria-labelledby="t">
            <p class="text-sm text-muted">{{ orgName }}</p>
            <h1 id="t" class="sr-only">Verifica della tessera</h1>
            <template v-if="card">
                <p class="mt-2 text-xl font-bold">{{ card.name }}</p>
                <p v-if="card.number" class="text-sm text-muted">Tessera n. {{ card.number }}</p>
                <p class="mt-4 rounded-xl px-4 py-3 text-lg font-bold" :class="card.active ? 'bg-salvia/20' : 'bg-danger/15 text-danger'">
                    {{ card.active ? `Tessera valida (${card.year})` : 'Tessera non valida oggi' }}</p>
            </template>
            <p v-else class="mt-4 rounded-xl bg-danger/15 px-4 py-3 text-lg font-bold text-danger">Tessera non trovata</p>
        </section>
    </main>
</template>
