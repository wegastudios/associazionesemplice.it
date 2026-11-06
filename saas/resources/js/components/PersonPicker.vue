<script setup>
import { ref, watch } from 'vue';

const model = defineModel({ type: String, default: '' });
const props = defineProps({ id: { type: String, required: true }, placeholder: { type: String, default: 'Cerca per nome o tessera' } });

const query = ref('');
const results = ref([]);
const chosen = ref('');
let timer = null;

watch(query, (q) => {
    clearTimeout(timer);
    if (q.trim().length < 2 || q === chosen.value) {
        results.value = [];
        return;
    }
    timer = setTimeout(async () => {
        const res = await fetch(`/gestionale/soci/cerca?q=${encodeURIComponent(q)}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
        results.value = res.ok ? await res.json() : [];
    }, 250);
});

// Se il modulo si azzera (dopo il salvataggio), si svuota anche la ricerca.
watch(model, (v) => {
    if (!v && chosen.value) {
        chosen.value = '';
        query.value = '';
    }
});

const pick = (r) => {
    model.value = r.id;
    chosen.value = r.label;
    query.value = r.label;
    results.value = [];
};
const clear = () => {
    model.value = '';
    chosen.value = '';
    query.value = '';
};
</script>

<template>
    <div class="relative">
        <div class="flex gap-2">
            <input :id="props.id" v-model="query" type="search" autocomplete="off" :placeholder="props.placeholder"
                class="field" role="combobox"
                :aria-expanded="results.length > 0" :aria-controls="`${props.id}-list`" />
            <button v-if="model" type="button" class="text-sm font-semibold text-accent" @click="clear">Togli</button>
        </div>
        <ul v-if="results.length" :id="`${props.id}-list`" role="listbox" class="absolute z-10 mt-1 w-full overflow-hidden rounded-lg border border-line bg-card shadow">
            <li v-for="r in results" :key="r.id">
                <button type="button" role="option" class="block w-full px-3 py-2 text-left hover:bg-surface" @click="pick(r)">{{ r.label }}</button>
            </li>
        </ul>
    </div>
</template>
