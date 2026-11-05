<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MemberLayout from '../../layouts/MemberLayout.vue';

const props = defineProps({ member: Object, resources: Array, chosen: String, date: String, today: String, slots: Array, dayFree: Boolean, mine: Array });
const res = computed(() => props.resources.find((r) => r.id === props.chosen));
const go = (q) => router.get('/area/risorse', { risorsa: props.chosen, giorno: props.date, ...q }, { preserveScroll: true });
const form = useForm({ date: props.date, start: '', units: 1, guests: '' });
const book = (start) => {
    form.date = props.date;
    form.start = start ?? '';
    form.post(`/area/risorse/${props.chosen}/prenota`, { preserveScroll: true, onSuccess: () => form.reset('guests') });
};
const cancel = (x) => confirm(`Annullare ${x.resource} (${x.when})?`) && router.post(`/area/risorse/prenotazioni/${x.id}/annulla`, {}, { preserveScroll: true });
const field = 'rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Prenota" />
    <MemberLayout>
        <h1 class="mb-4 text-2xl font-bold">Prenota</h1>
        <div class="mb-4 flex flex-wrap gap-2 text-sm">
            <button v-for="r in resources" :key="r.id" type="button" class="rounded-full border px-3 py-1"
                :class="r.id === chosen ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card'" :aria-pressed="r.id === chosen" @click="go({ risorsa: r.id })">{{ r.name }}</button>
        </div>
        <section v-if="res" class="mb-6 rounded-2xl border border-line bg-card p-4">
            <div class="mb-3 flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 class="text-lg font-bold">{{ res.name }}</h2>
                    <p class="text-sm text-muted">{{ res.typology_label }} · {{ res.fee_label }}<template v-if="res.typology === 'hourly'"> a fascia</template><template v-else> al giorno</template></p>
                    <p v-if="res.description" class="mt-1 text-sm">{{ res.description }}</p>
                </div>
                <label class="text-sm"><span class="sr-only">Giorno</span><input type="date" :value="date" :min="today" :class="field" @change="go({ giorno: $event.target.value })" /></label>
            </div>
            <label v-if="res.guests_max > 0" class="mb-3 block space-y-1 text-sm"><span class="font-semibold">Ospiti (massimo {{ res.guests_max }})</span>
                <input v-model="form.guests" :class="[field, 'block w-full']" placeholder="nomi separati da virgola" /></label>
            <div v-if="res.typology === 'hourly'">
                <p v-if="!slots.length" class="text-sm text-muted">Nessuna fascia in questo giorno.</p>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                    <button v-for="s in slots" :key="s.start" type="button" :disabled="!s.free || form.processing"
                        class="rounded-lg border px-3 py-2 text-left text-sm" :class="s.free ? 'border-salvia/40 bg-salvia/10 hover:border-accent' : 'border-line bg-surface text-muted'"
                        @click="book(s.start)">
                        <span class="font-semibold tabular-nums">{{ s.start }}–{{ s.end }}</span>
                        <span class="block">{{ s.free ? s.price_label : (s.past ? 'passata' : 'occupata') }}</span>
                    </button>
                </div>
            </div>
            <div v-else class="flex flex-wrap items-center gap-3 text-sm">
                <span :class="dayFree ? '' : 'text-danger'">{{ dayFree ? 'Libera in questo giorno' : 'Occupata in questo giorno' }}</span>
                <button v-if="dayFree" type="button" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="form.processing" @click="book(null)">Prenota il giorno</button>
            </div>
            <p class="mt-3 text-xs text-muted">Tocca una fascia libera per prenotarla. Il prezzo è quello dei soci con la tessera valida.</p>
        </section>
        <section class="rounded-2xl border border-line bg-card p-4" aria-labelledby="m">
            <h2 id="m" class="mb-2 text-lg font-bold">Le tue prenotazioni</h2>
            <p v-if="!mine.length" class="text-sm text-muted">Nessuna.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="x in mine" :key="x.id" class="flex flex-wrap items-center gap-3 py-2">
                    <span class="font-semibold">{{ x.resource }}</span><span>{{ x.when }}</span><span class="text-muted">{{ x.fee_label }}</span>
                    <span v-if="x.due > 0" class="text-xs text-danger">da pagare</span>
                    <button v-if="x.cancellable" type="button" class="ml-auto text-danger hover:underline" @click="cancel(x)">Annulla</button>
                </li>
            </ul>
        </section>
    </MemberLayout>
</template>
