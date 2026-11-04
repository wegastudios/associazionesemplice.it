<script setup>
import { Head } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

defineProps({
    peopleCount: { type: Number, required: true },
});
</script>

<template>
    <Head title="Gestionale" />
    <AppLayout>
        <h1 class="mb-6 text-2xl font-bold">Gestionale</h1>
        <div class="grid gap-4 sm:grid-cols-3">
            <section class="rounded-2xl border border-line bg-card p-5">
                <h2 class="text-sm font-semibold text-muted">Schede in anagrafica</h2>
                <p class="mt-2 text-3xl font-bold tabular-nums">{{ peopleCount }}</p>
            </section>
        </div>
    </AppLayout>
</template>
