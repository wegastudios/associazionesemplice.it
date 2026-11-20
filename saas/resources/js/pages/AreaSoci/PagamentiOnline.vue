<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

// Le pagine ufficiali dei gestori: nessuna cifra nostra, ogni gestore dice le sue condizioni.
const links = {
    stripeNonprofit: 'https://stripe.com/it/industries/nonprofits',
    stripeKeys: 'https://dashboard.stripe.com/apikeys',
    paypalNonprofit: 'https://www.paypal.com/it/business/nonprofit',
    paypalKeys: 'https://developer.paypal.com/dashboard/applications/live',
};
const props = defineProps({ settings: Object, webhookUrl: String, accounts: Array, payments: Array, canSettings: Boolean, canAccounting: Boolean, canRefund: Boolean });
const s = props.settings;
const form = useForm({
    stripe_enabled: s.stripe_enabled, stripe_secret: '', stripe_webhook: '', paypal_enabled: s.paypal_enabled, paypal_client_id: s.paypal_client_id,
    paypal_secret: '', paypal_live: s.paypal_live, account_id: s.account_id || '', forget: [],
});
const save = () => form.transform((d) => ({ ...d, account_id: d.account_id || null })).put('/gestionale/pagamenti-online', { preserveScroll: true, onSuccess: () => form.reset('stripe_secret', 'stripe_webhook', 'paypal_secret', 'forget') });
const test = (p) => router.post(`/gestionale/pagamenti-online/prova/${p}`, {}, { preserveScroll: true });
const reviewed = (p) => router.post(`/gestionale/pagamenti-online/${p.id}/controllato`, {}, { preserveScroll: true });
const comma = (c) => (c / 100).toFixed(2).replace('.', ',');
const askReason = () => window.prompt('Motivo del rimborso (lo leggerà il socio nell’email):');
const refund = (p) => {
    const amount = window.prompt(`Quanto restituire al socio (massimo ${comma(p.refundable)} €)? I soldi tornano sul suo metodo di pagamento e non si può annullare.${p.has_receipts ? ' Le voci già registrate in prima nota vanno annullate a parte, oppure usa «rimborsa e annulla».' : ''}`, p.has_receipts ? '' : comma(p.refundable));
    const reason = amount ? askReason() : null;
    if (amount && reason) { router.post(`/gestionale/pagamenti-online/${p.id}/rimborso`, { amount, reason }, { preserveScroll: true }); }
};
const refundVoid = (p) => {    if (!window.confirm(`Restituire al socio tutto il pagamento e annullare in prima nota le sue ricevute? Le tessere collegate vengono annullate e i soldi tornano sul suo metodo di pagamento: non si può annullare. Se l’anno fiscale dell’incasso è chiuso l’operazione non parte: né rimborso né annullamento.${p.stays_note ? " " + p.stays_note : ""}`)) { return; }
    const reason = askReason();
    if (reason) { router.post(`/gestionale/pagamenti-online/${p.id}/rimborso`, { reason }, { preserveScroll: true }); }
};
const field = 'field';
const states = { created: 'avviato', pending: 'in attesa', settled: 'registrato', review: 'da controllare', failed: 'non riuscito', cancelled: 'annullato' };
</script>

<template>
    <Head title="Pagamenti online" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Pagamenti online</h1>
        <p class="mb-6 text-sm text-muted">I soci pagano dall'area soci con il conto Stripe o PayPal dell'associazione: i soldi arrivano direttamente all'associazione. Ogni pagamento va da solo in prima nota con la ricevuta.</p>

        <form v-if="canSettings" class="mb-6 card p-4 sm:p-5" @submit.prevent="save">
            <h2 class="mb-3 text-lg font-bold">Conti</h2>
            <label class="mb-4 block max-w-sm space-y-1 text-sm"><span class="font-semibold">Conto della prima nota per gli incassi online</span>
                <select v-model="form.account_id" :class="field"><option value="">scegli</option><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select>
                <span class="text-muted">Di solito un conto «Stripe» o «PayPal» di tipo banca: quando arriva il bonifico, registra un giroconto verso la banca.</span></label>
            <div class="grid gap-6 lg:grid-cols-2">
                <fieldset class="space-y-3 rounded-lg border border-line p-4 text-sm">
                    <legend class="px-1 font-bold">Stripe (carte)</legend>
                    <label class="flex items-center gap-2"><input v-model="form.stripe_enabled" type="checkbox" /> Attivo</label>
                    <ol class="list-decimal space-y-2 pl-5 text-muted">
                        <li>Ti serve un conto Stripe dell'associazione. Se è un ente non profit, guarda le condizioni che Stripe riserva agli enti: <a :href="links.stripeNonprofit" class="text-accent hover:underline" target="_blank" rel="noopener">Stripe per il non profit</a>.</li>
                        <li><a :href="links.stripeKeys" class="text-accent hover:underline" target="_blank" rel="noopener">Apri la pagina delle chiavi di Stripe</a> e crea una chiave segreta. Se scegli una chiave limitata, deve poter scrivere su «Checkout Sessions», «Refunds» e «Webhook Endpoints».</li>
                        <li>Incolla la chiave qui sotto e premi «Salva»: verifico subito che funzioni e creo da solo il webhook, cioè l'avviso con cui Stripe ci dice che il pagamento è arrivato.</li>
                    </ol>
                    <label class="block space-y-1"><span class="font-semibold">Chiave segreta</span><input v-model="form.stripe_secret" type="password" autocomplete="off" :class="field" :placeholder="s.stripe_secret_set ? 'impostata: scrivi per cambiarla' : 'sk_live_…'" /></label>
                    <p v-if="s.stripe_webhook_set" class="text-muted">Il webhook è impostato.</p>
                    <details class="text-muted"><summary class="cursor-pointer font-semibold">Se il webhook non si crea da solo</summary>
                        <p class="mt-2">Nel pannello di Stripe aggiungi il webhook <code class="break-all rounded bg-surface px-1">{{ webhookUrl }}</code> per l'evento «checkout.session.completed», poi copia qui il suo segreto di firma.</p>
                        <label class="mt-2 block space-y-1"><span class="font-semibold">Segreto del webhook</span><input v-model="form.stripe_webhook" type="password" autocomplete="off" :class="field" :placeholder="s.stripe_webhook_set ? 'impostato: scrivi per cambiarlo' : 'whsec_…'" /></label>
                    </details>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="rounded-lg border border-line px-3 py-1.5 font-semibold" @click="test('stripe')">Prova di nuovo la connessione</button>
                        <label v-if="s.stripe_secret_set" class="flex items-center gap-1 text-danger"><input v-model="form.forget" type="checkbox" value="stripe_secret" /> togli la chiave</label>
                    </div>
                </fieldset>
                <fieldset class="space-y-3 rounded-lg border border-line p-4 text-sm">
                    <legend class="px-1 font-bold">PayPal</legend>
                    <label class="flex items-center gap-2"><input v-model="form.paypal_enabled" type="checkbox" /> Attivo</label>
                    <ol class="list-decimal space-y-2 pl-5 text-muted">
                        <li>Ti serve un conto PayPal Business dell'associazione. Se è un ente non profit, guarda la tariffa che PayPal riserva agli enti benefici: <a :href="links.paypalNonprofit" class="text-accent hover:underline" target="_blank" rel="noopener">PayPal per il non profit</a>.</li>
                        <li><a :href="links.paypalKeys" class="text-accent hover:underline" target="_blank" rel="noopener">Apri la pagina delle credenziali di PayPal</a>, crea un'app e copia il «Client ID» e il «Segreto».</li>
                        <li>Incollali qui sotto e premi «Salva»: verifico subito che funzionino.</li>
                    </ol>
                    <label class="block space-y-1"><span class="font-semibold">Client ID</span><input v-model="form.paypal_client_id" autocomplete="off" :class="field" /></label>
                    <label class="block space-y-1"><span class="font-semibold">Segreto</span><input v-model="form.paypal_secret" type="password" autocomplete="off" :class="field" :placeholder="s.paypal_secret_set ? 'impostato: scrivi per cambiarlo' : ''" /></label>
                    <label class="flex items-center gap-2"><input v-model="form.paypal_live" type="checkbox" /> Conto reale (togli la spunta per fare prove)</label>
                    <div class="flex flex-wrap gap-3">
                        <button type="button" class="rounded-lg border border-line px-3 py-1.5 font-semibold" @click="test('paypal')">Prova di nuovo la connessione</button>
                        <label v-if="s.paypal_secret_set" class="flex items-center gap-1 text-danger"><input v-model="form.forget" type="checkbox" value="paypal_secret" /> togli il segreto</label>
                    </div>
                </fieldset>
            </div>
            <p v-for="(e, k) in form.errors" :key="k" class="mt-2 text-sm text-danger" role="alert">{{ e }}</p>
            <div class="mt-4 flex flex-wrap items-center gap-3">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Salva</button>
                <span class="text-sm text-muted">Pronti per i soci: {{ s.ready.length ? s.ready.join(', ') : 'nessuno' }}. Le chiavi salvate non si possono rileggere: per cambiarle, scrivile di nuovo.</span>
            </div>
        </form>

        <section class="card overflow-x-auto p-5" aria-labelledby="l">
            <h2 id="l" class="mb-3 text-lg font-bold">Pagamenti dei soci</h2>
            <p v-if="!payments.length" class="text-sm text-muted">Nessun pagamento online.</p>
            <table v-else class="w-full text-sm">
                <thead class="text-left text-muted"><tr><th class="py-2 pr-3">Data</th><th class="pr-3">Socio</th><th class="pr-3">Cosa ha pagato</th><th class="pr-3">Importo</th><th class="pr-3">Stato</th><th>Ricevute</th></tr></thead>
                <tbody>
                    <tr v-for="p in payments" :key="p.id" class="border-t border-line align-top">
                        <td class="whitespace-nowrap py-2 pr-3 tabular-nums">{{ p.date }}</td>
                        <td class="pr-3">{{ p.person }}</td>
                        <td class="pr-3">{{ p.items }}<p v-if="p.error" class="text-xs text-danger">{{ p.error }}</p></td>
                        <td class="whitespace-nowrap pr-3 tabular-nums">{{ p.amount }} <span class="text-muted">{{ p.provider }}</span></td>
                        <td class="whitespace-nowrap pr-3"><span :class="p.status === 'review' ? 'font-semibold text-danger' : ''">{{ states[p.status] ?? p.status }}</span>
                            <button v-if="p.status === 'review' && canAccounting" type="button" class="ml-2 text-accent hover:underline" @click="reviewed(p)">Segna come controllato</button>
                            <button v-if="p.status === 'review' && canRefund && p.refundable > 0" type="button" class="ml-2 text-accent hover:underline" @click="refund(p)">rimborsa</button>
                            <button v-if="canRefund && p.voidable" type="button" class="ml-2 text-accent hover:underline" @click="refundVoid(p)">rimborsa e annulla</button>
                            <p v-if="p.refunded !== '0,00 €' && p.refunded !== '0,00'" class="text-xs text-muted">restituiti {{ p.refunded }}</p></td>
                        <td>{{ p.receipts }}</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>
