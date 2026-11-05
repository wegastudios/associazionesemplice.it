<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import MemberLayout from '../../layouts/MemberLayout.vue';

const props = defineProps({ member: Object, providers: Array, dues: Array, history: Array });
const form = useForm({ keys: props.dues.map((d) => d.key), provider: '' });
const groups = computed(() => {
    const out = {};
    for (const d of props.dues) (out[d.group] ??= []).push(d);
    return out;
});
const total = computed(() => props.dues.filter((d) => form.keys.includes(d.key)).reduce((s, d) => s + d.amount_cents, 0));
const euro = (c) => (c / 100).toLocaleString('it-IT', { style: 'currency', currency: 'EUR' });
const pay = (provider) => {
    form.provider = provider;
    form.post('/area/paga');
};
</script>

<template>
    <Head title="Paga online" />
    <MemberLayout>
        <h1 class="mb-4 text-2xl font-bold">Paga online</h1>
        <p v-if="!dues.length" class="mb-6 rounded-2xl border border-line bg-card p-6 text-center text-muted">Non hai niente da pagare. 🎉</p>
        <template v-else>
            <p v-if="!providers.length" class="mb-4 rounded-lg border border-line bg-card px-3 py-2 text-sm">L'associazione non ha ancora attivato i pagamenti online: puoi pagare in segreteria.</p>
            <form class="mb-6 space-y-4" @submit.prevent>
                <section v-for="(items, g) in groups" :key="g" class="rounded-2xl border border-line bg-card p-4">
                    <h2 class="mb-2 font-bold">{{ g }}</h2>
                    <label v-for="d in items" :key="d.key" class="flex items-center gap-3 border-t border-line py-2 text-sm first:border-t-0">
                        <input v-model="form.keys" type="checkbox" :value="d.key" :disabled="!providers.length" />
                        <span class="min-w-0 flex-1">{{ d.label }}</span>
                        <span class="font-semibold tabular-nums">{{ d.amount_label }}</span>
                    </label>
                </section>
                <div v-if="providers.length" class="sticky bottom-16 rounded-2xl border border-line bg-card p-4 shadow-sm sm:bottom-2">
                    <p class="mb-3 flex justify-between text-lg font-bold"><span>Totale</span><span class="tabular-nums">{{ euro(total) }}</span></p>
                    <div class="flex flex-wrap gap-3">
                        <button v-if="providers.includes('stripe')" type="button" class="flex-1 rounded-lg bg-accent px-4 py-3 font-semibold text-accent-ink" :disabled="!form.keys.length || form.processing" @click="pay('stripe')">Paga con carta</button>
                        <button v-if="providers.includes('paypal')" type="button" class="flex-1 rounded-lg border border-line px-4 py-3 font-semibold" :disabled="!form.keys.length || form.processing" @click="pay('paypal')">Paga con PayPal</button>
                    </div>
                    <p class="mt-2 text-xs text-muted">Paghi sulla pagina sicura del servizio scelto; l'associazione non vede i dati della carta. La ricevuta arriva qui.</p>
                </div>
            </form>
        </template>
        <section v-if="history.length" class="rounded-2xl border border-line bg-card p-5" aria-labelledby="h">
            <h2 id="h" class="mb-3 text-lg font-bold">Pagamenti online</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="p in history" :key="p.id" class="py-2">
                    <div class="flex flex-wrap gap-2"><span class="font-semibold">{{ p.date }}</span><span>{{ p.amount }}</span><span class="text-muted">{{ p.provider }} · {{ p.status }}</span>
                        <span v-if="p.receipts.length" class="text-muted">· ricevute {{ p.receipts.join(', ') }}</span></div>
                    <p class="truncate text-xs text-muted">{{ p.items }}</p>
                </li>
            </ul>
        </section>
    </MemberLayout>
</template>
