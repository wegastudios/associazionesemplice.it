<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ settings: Object, webhookUrl: String, accounts: Array, payments: Array, canSettings: Boolean, canAccounting: Boolean });
const s = props.settings;
const form = useForm({
    stripe_enabled: s.stripe_enabled, stripe_secret: '', stripe_webhook: '', paypal_enabled: s.paypal_enabled, paypal_client_id: s.paypal_client_id,
    paypal_secret: '', paypal_live: s.paypal_live, account_id: s.account_id || '', forget: [],
});
const save = () => form.transform((d) => ({ ...d, account_id: d.account_id || null })).put('/gestionale/pagamenti-online', { preserveScroll: true, onSuccess: () => form.reset('stripe_secret', 'stripe_webhook', 'paypal_secret', 'forget') });
const test = (p) => router.post(`/gestionale/pagamenti-online/prova/${p}`, {}, { preserveScroll: true });
const reviewed = (p) => router.post(`/gestionale/pagamenti-online/${p.id}/controllato`, {}, { preserveScroll: true });
const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink disabled:opacity-70';
const states = { created: 'avviato', pending: 'in attesa', settled: 'registrato', review: 'da controllare', failed: 'non riuscito', cancelled: 'annullato' };
</script>

<template>
    <Head title="Pagamenti online" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Pagamenti online</h1>
        <p class="mb-6 text-sm text-muted">I soci pagano dall'area soci con il conto Stripe o PayPal dell'associazione: i soldi arrivano a voi, senza commissioni nostre. Ogni pagamento va da solo in prima nota con la ricevuta.</p>

        <form v-if="canSettings" class="mb-6 rounded-2xl border border-line bg-card p-5" @submit.prevent="save">
            <h2 class="mb-3 text-lg font-bold">Conti</h2>
            <label class="mb-4 block max-w-sm space-y-1 text-sm"><span class="font-semibold">Conto della prima nota per gli incassi online</span>
                <select v-model="form.account_id" :class="field"><option value="">scegli</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                <span class="text-muted">Di solito un conto «Stripe» o «PayPal» di tipo banca, da girocontare quando arrivano i bonifici.</span></label>
            <div class="grid gap-6 lg:grid-cols-2">
                <fieldset class="space-y-3 rounded-lg border border-line p-4 text-sm">
                    <legend class="px-1 font-bold">Stripe (carte)</legend>
                    <label class="flex items-center gap-2"><input v-model="form.stripe_enabled" type="checkbox" /> Attivo</label>
                    <label class="block space-y-1"><span class="font-semibold">Chiave segreta</span><input v-model="form.stripe_secret" type="password" autocomplete="off" :class="field" :placeholder="s.stripe_secret_set ? 'impostata: scrivi per cambiarla' : 'sk_live_…'" /></label>
                    <label class="block space-y-1"><span class="font-semibold">Segreto del webhook</span><input v-model="form.stripe_webhook" type="password" autocomplete="off" :class="field" :placeholder="s.stripe_webhook_set ? 'impostato: scrivi per cambiarlo' : 'whsec_…'" /></label>
                    <p class="text-muted">Nel pannello di Stripe aggiungi il webhook <code class="break-all rounded bg-surface px-1">{{ webhookUrl }}</code> per l'evento «checkout.session.completed».</p>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="rounded-lg border border-line px-3 py-1.5 font-semibold" @click="test('stripe')">Prova le chiavi</button>
                        <label v-if="s.stripe_secret_set" class="flex items-center gap-1 text-danger"><input v-model="form.forget" type="checkbox" value="stripe_secret" /> togli la chiave</label>
                    </div>
                </fieldset>
                <fieldset class="space-y-3 rounded-lg border border-line p-4 text-sm">
                    <legend class="px-1 font-bold">PayPal</legend>
                    <label class="flex items-center gap-2"><input v-model="form.paypal_enabled" type="checkbox" /> Attivo</label>
                    <label class="block space-y-1"><span class="font-semibold">Client ID</span><input v-model="form.paypal_client_id" autocomplete="off" :class="field" /></label>
                    <label class="block space-y-1"><span class="font-semibold">Segreto</span><input v-model="form.paypal_secret" type="password" autocomplete="off" :class="field" :placeholder="s.paypal_secret_set ? 'impostato: scrivi per cambiarlo' : ''" /></label>
                    <label class="flex items-center gap-2"><input v-model="form.paypal_live" type="checkbox" /> Conto reale (togli per la prova «sandbox»)</label>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="rounded-lg border border-line px-3 py-1.5 font-semibold" @click="test('paypal')">Prova le chiavi</button>
                        <label v-if="s.paypal_secret_set" class="flex items-center gap-1 text-danger"><input v-model="form.forget" type="checkbox" value="paypal_secret" /> togli il segreto</label>
                    </div>
                </fieldset>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="mt-2 text-sm text-danger" role="alert">{{ e }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="form.processing">Salva</button>
                <span class="text-sm text-muted">Pronti per i soci: {{ s.ready.length ? s.ready.join(', ') : 'nessuno' }}. Le chiavi sono cifrate e non si rileggono.</span>
            </div>
        </form>

        <section class="overflow-x-auto rounded-2xl border border-line bg-card p-5" aria-labelledby="l">
            <h2 id="l" class="mb-3 text-lg font-bold">Pagamenti dei soci</h2>
            <p v-if="!payments.length" class="text-sm text-muted">Nessun pagamento online.</p>
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted"><tr><th class="py-2 pr-3">Data</th><th class="pr-3">Socio</th><th class="pr-3">Voci</th><th class="pr-3">Importo</th><th class="pr-3">Stato</th><th>Ricevute</th></tr></thead>
                <tbody>
                    <tr v-for="p in payments" :key="p.id" class="border-t border-line align-top">
                        <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ p.date }}</td>
                        <td class="pr-3">{{ p.person }}</td>
                        <td class="pr-3">{{ p.items }}<p v-if="p.error" class="text-xs text-danger">{{ p.error }}</p></td>
                        <td class="whitespace-nowrap pr-3 tabular-nums">{{ p.amount }} <span class="text-muted">{{ p.provider }}</span></td>
                        <td class="whitespace-nowrap pr-3"><span :class="p.status === 'review' ? 'font-semibold text-danger' : ''">{{ states[p.status] ?? p.status }}</span>
                            <button v-if="p.status === 'review' && canAccounting" type="button" class="ml-2 text-accent hover:underline" @click="reviewed(p)">controllato</button></td>
                        <td>{{ p.receipts }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>
