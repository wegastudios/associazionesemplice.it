<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({
    activity: Object, month: String, monthLabel: String, previous: String, next: String, dates: Array, day: String, dayLabel: String,
    future: Boolean, saved: Boolean, roster: Array, summary: Object, canEdit: Boolean,
});
const go = (q) => router.get(`/gestionale/attivita/${props.activity.id}/presenze`, q, { preserveScroll: true });
const form = useForm({ date: props.day, present: props.roster.filter((p) => p.present).map((p) => p.id) });
watch(() => [props.day, props.roster], () => {
    form.date = props.day;
    form.present = props.roster.filter((p) => p.present).map((p) => p.id);
});
const save = () => form.post(`/gestionale/attivita/${props.activity.id}/presenze`, { preserveScroll: true });
const all = (on) => { form.present = on ? props.roster.map((p) => p.id) : []; };
</script>

<template>
    <Head :title="`Presenze · ${activity.name}`" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link :href="`/gestionale/attivita/${activity.id}`" class="text-accent hover:underline">← {{ activity.name }}</Link></p>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <h1 class="text-2xl font-bold">Presenze</h1>
            <div class="flex items-center gap-2 text-sm">
                <button type="button" class="field w-auto" aria-label="Mese prima" @click="go({ mese: previous })">←</button>
                <span class="min-w-32 text-center font-semibold first-letter:uppercase">{{ monthLabel }}</span>
                <button type="button" class="field w-auto" aria-label="Mese dopo" @click="go({ mese: next })">→</button>
            </div>
        </div>

        <p v-if="!dates.length" class="card p-5 sm:p-6 text-center text-muted">In questo mese l'attività non ha lezioni o date.</p>
        <template v-else>
            <nav class="mb-4 flex flex-wrap gap-2 text-sm" aria-label="Lezioni del mese">
                <button v-for="d in dates" :key="d.value" type="button" :class="['rounded-lg border px-3 py-1.5', d.value === day ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card', d.done && d.value !== day ? 'font-semibold' : '']"
                    :aria-current="d.value === day ? 'date' : undefined" @click="go({ mese: month, giorno: d.value })">{{ d.label }}<span v-if="d.done"> ✓</span></button>
            </nav>

            <form class="mb-6 card p-4 sm:p-5" @submit.prevent="save">
                <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                    <h2 class="text-lg font-bold first-letter:uppercase">{{ dayLabel }}</h2>
                    <span class="text-sm text-muted">{{ saved ? 'Registrata' : 'Non ancora registrata' }}</span>
                </div>
                <p v-if="!roster.length" class="text-sm text-muted">Nessuno atteso in questa data.</p>
                <fieldset v-else :disabled="!canEdit || future" class="grid gap-2 text-sm sm:grid-cols-2 lg:grid-cols-3">
                    <legend class="sr-only">Presenti</legend>
                    <label v-for="p in roster" :key="p.id" class="flex items-center gap-2 field w-auto"><input v-model="form.present" type="checkbox" :value="p.id" /> {{ p.name }}</label>
                </fieldset>
                <div v-if="canEdit && roster.length" class="mt-4 flex flex-wrap items-center gap-3">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing || future">Registra le presenze</button>
                    <button type="button" class="text-sm text-accent hover:underline" :disabled="future" @click="all(true)">tutti presenti</button>
                    <button type="button" class="text-sm text-accent hover:underline" :disabled="future" @click="all(false)">nessuno</button>
                    <span v-if="future" class="text-sm text-muted">Si registrano a lezione fatta.</span>
                </div>
            </form>

            <section v-if="summary.people.length" class="card overflow-x-auto p-5" aria-labelledby="sum-title">
                <h2 id="sum-title" class="mb-3 text-lg font-bold">Riepilogo del mese</h2>
                <table class="text-sm">
                    <thead><tr><th class="py-1 pr-4 text-left font-semibold">Persona</th><th v-for="d in summary.dates" :key="d.value" class="px-1 text-center font-semibold text-muted">{{ d.label }}</th><th class="pl-4 text-right font-semibold">Presenze</th></tr></thead>
                    <tbody>
                        <tr v-for="p in summary.people" :key="p.id" class="border-t border-line">
                            <td class="py-1 pr-4">{{ p.name }}</td>
                            <td v-for="d in summary.dates" :key="d.value" class="px-1 text-center" :class="p.by_date[d.value] === false ? 'text-danger' : ''">{{ p.by_date[d.value] === undefined ? '' : (p.by_date[d.value] ? '●' : '○') }}</td>
                            <td class="pl-4 text-right tabular-nums">{{ p.present }}/{{ p.total }}</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </AppLayout>
</template>
