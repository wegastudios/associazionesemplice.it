<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ hasVat: Boolean, rates: String, includesVat: Boolean, membershipRate: Number, memberEventsDonation: Boolean });
const donation = useForm({ member_events_donation: props.memberEventsDonation });
const saveDonation = () => donation.put('/gestionale/aspetto-fiscale/erogazioni-liberali', { preserveScroll: true });
const form = useForm({ rates: props.rates, includes_vat: props.includesVat, membership_rate: props.membershipRate });
const save = () => form.put('/gestionale/aspetto-fiscale', { preserveScroll: true });
const parsed = () => [...new Set(form.rates.split(/[\s,;]+/).map((r) => Number(r)).filter((r) => Number.isInteger(r) && r >= 1 && r <= 30))].sort((a, b) => b - a);
const label = 'space-y-1 text-sm';
</script>

<template>
    <Head title="Aspetto fiscale" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Aspetto fiscale</h1>
        <p v-if="!hasVat" class="card p-4 text-sm sm:p-5">Senza partita IVA l'IVA non si applica: incassi e spese si registrano con il loro importo, senza aliquote. Se l'associazione ha la partita IVA, indicala in «Configurazione guidata», tra i dati dell'ente: qui compariranno le aliquote.</p>
        <form v-else class="card space-y-5 p-4 sm:p-5" novalidate @submit.prevent="save">
            <label :class="label"><span class="font-semibold">Aliquote IVA che usi</span>
                <input v-model="form.rates" class="field" inputmode="numeric" placeholder="es. 22, 10, 5, 4" />
                <span class="block text-muted">Numeri separati da virgola. «IVA esente» c'è sempre.</span>
                <span v-if="form.errors.rates" class="block font-semibold text-danger" role="alert">{{ form.errors.rates }}</span></label>
            <fieldset class="space-y-2 text-sm"><legend class="font-semibold">I prezzi che inserisci sono</legend>
                <label class="flex items-center gap-2"><input v-model="form.includes_vat" type="radio" :value="true" /> finali, con l'IVA compresa</label>
                <label class="flex items-center gap-2"><input v-model="form.includes_vat" type="radio" :value="false" /> senza IVA: l'IVA si aggiunge</label></fieldset>
            <label :class="label"><span class="font-semibold">Aliquota della quota associativa</span>
                <select v-model.number="form.membership_rate" class="field"><option :value="0">IVA esente</option><option v-for="r in parsed()" :key="r" :value="r">IVA {{ r }}%</option></select>
                <span v-if="form.errors.membership_rate" class="block font-semibold text-danger" role="alert">{{ form.errors.membership_rate }}</span></label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Salva</button>
        </form>
        <form class="card mt-4 space-y-3 p-4 sm:p-5" novalidate @submit.prevent="saveDonation">
            <h2 class="text-lg font-semibold">Erogazioni liberali</h2>
            <label class="flex items-start gap-2 text-sm"><input v-model="donation.member_events_donation" type="checkbox" class="mt-1" />
                <span>Incassa come erogazione liberale gli eventi e i corsi pagati dai soci</span></label>
            <p class="text-sm text-muted">Spenta, gli eventi e i corsi pagati dai soci sono contributi, come sempre. Accesa, da quel momento la ricevuta scrive «Erogazione liberale per l'evento…» o «…per il corso…», il rendiconto li conta tra le erogazioni liberali, con PayPal la voce è indicata come donazione e, se l'ente applica l'IVA, restano fuori campo IVA. Non cambia nulla per i pagamenti dei non soci, che restano contributi, e per le quote associative, che restano sempre escluse. I movimenti già registrati non cambiano. <strong>Prima di accenderla, chiedi al tuo commercialista:</strong> questa impostazione non garantisce alcun vantaggio fiscale a chi paga.</p>
            <button type="submit" class="btn btn-primary" :disabled="donation.processing">Salva</button>
        </form>
    </AppLayout>
</template>
