<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({ status: String, canSkip: { type: Boolean, default: true }, steps: Array, can: Object });

const by = Object.fromEntries(props.steps.map((s) => [s.key, s.props]));

// Quante attività e risorse hanno l'IVA: dice solo la parte presente.
const vatCount = (e) => [
    e.vat_activities > 0 ? `${e.vat_activities} attività` : null,
    e.vat_resources > 0 ? `${e.vat_resources} ${e.vat_resources === 1 ? 'risorsa' : 'risorse'}` : null,
].filter(Boolean).join(' e ');
const initial = {};
if (by.ente) initial.ente = { ...by.ente.values };
if (by.anno_sociale) initial.anno_sociale = { start_month: by.anno_sociale.start_month ?? '' };
if (by.anno_fiscale) initial.anno_fiscale = { start_month: by.anno_fiscale.start_month ?? '', confirm_move: false, confirm_cards: false };
if (by.tesseramento) initial.tesseramento = { fee: by.tesseramento.fee };
if (by.conti) initial.conti = { openings: Object.fromEntries(by.conti.accounts.map((a) => [a.id, a.opening])), bank_name: '', bank_opening: '' };
if (by.soci) initial.soci = { ...by.soci };
const form = useForm({ steps: initial });

// Anteprima delle tessere se cambia il mese dell'anno fiscale: si vede prima di salvare.
const cards = ref(null);
if (by.anno_fiscale) {
    watch(() => form.steps.anno_fiscale.start_month, async (m) => {
        cards.value = null;
        form.steps.anno_fiscale.confirm_cards = false;
        if (!by.anno_fiscale.start_month || !m || m === by.anno_fiscale.start_month) return;
        try {
            const r = await fetch(`/gestionale/configurazione/anteprima-tessere?mese=${m}`, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            if (r.ok && form.steps.anno_fiscale.start_month === m) cards.value = await r.json();
        } catch (e) {
            cards.value = null;
        }
    });
}
const save = () => form.post('/gestionale/configurazione', { preserveScroll: true, onError: () => document.querySelector('[role=alert]')?.scrollIntoView({ block: 'center' }) });
const skip = () => router.post('/gestionale/configurazione/rimanda');

const months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const field = 'field';
const label = 'space-y-1 text-sm';
const has = (k) => props.steps.some((s) => s.key === k);

// Indirizzo dell'area soci: copia negli appunti e conferma sul pulsante.
const copied = ref('');
const copy = async (url) => {
    try {
        await navigator.clipboard.writeText(url);
        copied.value = url;
        setTimeout(() => { copied.value = ''; }, 2500);
    } catch {
        copied.value = '';
    }
};
const title = (k) => props.steps.find((s) => s.key === k)?.title;
</script>

<template>
    <Head title="Configurazione" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Configurazione guidata</h1>
        <p class="mb-6 text-sm text-muted">Le impostazioni principali in una pagina: compila quello che sai, il resto si cambia dopo. Si salva tutto insieme.</p>

        <form class="space-y-6" novalidate @submit.prevent="save">
            <section v-if="has('anno_sociale') || has('anno_fiscale')" class="card p-4 sm:p-5" aria-labelledby="s-anni">
                <h2 id="s-anni" class="mb-3 text-lg font-bold">1. Anno delle attività e anno fiscale</h2>
                <p v-if="form.errors['steps.anno_sociale']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.anno_sociale'] }}</p>
                <p v-if="form.errors['steps.anno_fiscale']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.anno_fiscale'] }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label v-if="has('anno_sociale')" :class="label"><span class="font-semibold">Da che mese iniziano corsi ed eventi (anno delle attività)?</span>
                        <select v-model.number="form.steps.anno_sociale.start_month" :class="field" required><option value="" disabled>Scegli il mese</option><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select><span class="block text-sm text-muted">La tessera, le quote e gli incassi seguono invece l'anno fiscale.</span></label>
                    <label v-if="has('anno_fiscale')" :class="label"><span class="font-semibold">L'anno fiscale comincia a</span>
                        <select v-model.number="form.steps.anno_fiscale.start_month" :class="field" required :disabled="by.anno_fiscale.locked"><option value="" disabled>Scegli il mese</option><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select></label>
                </div>
                <p class="mt-2 text-sm text-muted">Con inizio a gennaio l'anno coincide con quello solare («2026»); altrimenti è a cavallo («2025/2026»). I due anni possono cominciare in mesi diversi. Sceglilo prima di chiudere il primo anno: dopo la prima chiusura non si può più cambiare.</p>
                <label v-if="has('anno_fiscale') && by.anno_fiscale.has_movements && by.anno_fiscale.start_month && form.steps.anno_fiscale.start_month !== by.anno_fiscale.start_month && !by.anno_fiscale.locked" class="mt-3 flex items-start gap-2 text-sm"><input v-model="form.steps.anno_fiscale.confirm_move" type="checkbox" class="mt-1" /><span>Cambiando il mese, i movimenti già registrati passano all'anno fiscale che inizia a {{ months[form.steps.anno_fiscale.start_month - 1] }}. Le date non cambiano. Le ricevute già emesse restano con il loro numero.</span></label>
                <div v-if="has('anno_fiscale') && cards && (cards.moved + cards.superseded > 0)" class="mt-3 rounded border border-line p-3 text-sm" role="status">
                    <h3 class="font-semibold">Cosa succede alle tessere</h3>
                    <p v-if="cards.moved > 0" class="mt-1">{{ cards.moved === 1 ? '1 tessera degli anni fiscali aperti passa al nuovo anno fiscale' : cards.moved + ' tessere degli anni fiscali aperti passano al nuovo anno fiscale' }} (per esempio «2025/2026 → 2026»).</p>
                    <p v-if="cards.superseded > 0" class="mt-1">{{ cards.superseded === 1 ? '1 resta «sostituita»' : cards.superseded + ' restano «sostituite»' }}: la persona ha già la tessera dello stesso anno e quella più recente resta valida.</p>
                    <p class="mt-1">Le tessere degli anni fiscali chiusi non si toccano.</p>
                    <ul class="mt-2 list-disc pl-5"><li v-for="l in cards.lines" :key="l">{{ l }}</li><li v-if="cards.more > 0">e altre {{ cards.more }}</li></ul>
                    <p class="mt-2 text-muted">Ogni tessera cambiata è scritta nel registro delle azioni, con l'etichetta e le date di prima.</p>
                    <label class="mt-2 flex items-start gap-2"><input v-model="form.steps.anno_fiscale.confirm_cards" type="checkbox" class="mt-1" /><span>Ho letto cosa succede alle tessere e voglio cambiare il mese dell'anno fiscale.</span></label>
                </div>
                <p v-else-if="has('anno_fiscale') && cards && by.anno_fiscale.start_month && form.steps.anno_fiscale.start_month !== by.anno_fiscale.start_month" class="mt-2 text-sm text-muted">Nessuna tessera da riallineare.</p>
            </section>

            <section v-if="has('ente')" class="card p-4 sm:p-5" aria-labelledby="s-ente">
                <h2 id="s-ente" class="mb-3 text-lg font-bold">2. {{ title('ente') }}</h2>
                <p v-if="form.errors['steps.ente']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.ente'] }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Forma dell'ente (obbligatoria)</span>
                        <select v-model="form.steps.ente.kind" required :class="field"><option value="" disabled>Scegli la forma</option><option v-for="k in by.ente.kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></label>
                    <label :class="label"><span class="font-semibold">Codice fiscale (obbligatorio)</span><input v-model="form.steps.ente.tax_code" required :class="field" maxlength="16" /></label>
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Legale rappresentante (obbligatorio)</span><input v-model="form.steps.ente.legal_rep" required :class="field" maxlength="120" autocomplete="name" />
                        <span class="block text-sm text-muted">Nome e cognome di chi rappresenta l'associazione, di solito il presidente. Compare nei dati esportati.</span></label>
                    <label :class="[label, 'sm:col-span-3']"><span class="font-semibold">Sede legale: indirizzo (obbligatorio)</span><input v-model="form.steps.ente.address" required :class="field" maxlength="190" /></label>
                    <label :class="label"><span class="font-semibold">CAP</span><input v-model="form.steps.ente.zip" required inputmode="numeric" :class="field" maxlength="5" /></label>
                    <label :class="label"><span class="font-semibold">Comune</span><input v-model="form.steps.ente.city" required :class="field" maxlength="120" /></label>
                    <label :class="label"><span class="font-semibold">Provincia (sigla)</span><input v-model="form.steps.ente.province" required :class="field" maxlength="2" /></label>
                    <label :class="label"><span class="font-semibold">Email della segreteria (obbligatoria)</span><input v-model="form.steps.ente.email" type="email" required :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Telefono della segreteria (obbligatorio)</span><input v-model="form.steps.ente.phone" type="tel" required :class="field" maxlength="30" /></label>
                    <p class="text-sm text-muted sm:col-span-3">Email e telefono li leggono i soci nelle email, nell'area soci e nel modulo di adesione, accanto al consenso sulla privacy: usa un recapito che qualcuno controlla davvero. Senza, la segreteria non può inviare le ricevute per email. Gli invii automatici, come il link di accesso, partono lo stesso.</p>
                    <div :class="label"><label class="block space-y-1"><span class="font-semibold">Partita IVA</span><input v-model="form.steps.ente.vat" :disabled="form.steps.ente.vat_none" :class="field" maxlength="16" /></label>
                        <label class="flex items-center gap-2"><input v-model="form.steps.ente.vat_none" type="checkbox" /> L'associazione non ha la partita IVA</label>
                        <p v-if="(by.ente.vat_activities > 0 || by.ente.vat_resources > 0) && (form.steps.ente.vat_none || !form.steps.ente.vat)" class="font-semibold text-danger" role="alert">Hai {{ vatCount(by.ente) }} con l'IVA: senza partita IVA l'IVA non si applica più a nessuna. I movimenti già registrati non cambiano.</p></div>
                    <div :class="label"><label class="block space-y-1"><span class="font-semibold">Numero RUNTS (con la sezione)</span><input v-model="form.steps.ente.runts" :disabled="form.steps.ente.runts_none" :class="field" maxlength="30" /></label>
                        <label class="flex items-center gap-2"><input v-model="form.steps.ente.runts_none" type="checkbox" /> L'associazione non è iscritta al RUNTS</label></div>
                    <div :class="label"><label class="block space-y-1"><span class="font-semibold">PEC</span><input v-model="form.steps.ente.pec" type="email" :disabled="form.steps.ente.pec_none" :class="field" /></label>
                        <label class="flex items-center gap-2"><input v-model="form.steps.ente.pec_none" type="checkbox" /> L'associazione non ha una PEC</label></div>
                    <label :class="label"><span class="font-semibold">Codice destinatario per la fattura elettronica (facoltativo)</span><input v-model="form.steps.ente.sdi" :class="field" maxlength="7" /></label>
                </div>
            </section>

            <section v-if="has('tesseramento')" class="card p-4 sm:p-5" aria-labelledby="s-tess">
                <h2 id="s-tess" class="mb-3 text-lg font-bold">3. {{ title('tesseramento') }}</h2>
                <p v-if="form.errors['steps.tesseramento']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.tesseramento'] }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label :class="label"><span class="font-semibold">Quota associativa</span><input v-model="form.steps.tesseramento.fee" inputmode="decimal" placeholder="es. 30,00" :class="field" /></label>
                </div>
            </section>

            <section v-if="has('conti')" class="card p-4 sm:p-5" aria-labelledby="s-conti">
                <h2 id="s-conti" class="mb-3 text-lg font-bold">4. {{ title('conti') }}</h2>
                <p v-if="form.errors['steps.conti']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.conti'] }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label v-for="a in by.conti.accounts" :key="a.id" :class="label"><span class="font-semibold">{{ a.name }}: saldo di partenza</span><input v-model="form.steps.conti.openings[a.id]" inputmode="decimal" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Aggiungi il conto in banca</span><input v-model="form.steps.conti.bank_name" placeholder="es. Banca Etica" :class="field" maxlength="120" /></label>
                    <label v-if="form.steps.conti.bank_name" :class="label"><span class="font-semibold">Saldo di partenza in banca</span><input v-model="form.steps.conti.bank_opening" inputmode="decimal" :class="field" /></label>
                </div>
            </section>

            <section v-if="has('soci')" class="card p-4 sm:p-5" aria-labelledby="s-soci">
                <h2 id="s-soci" class="mb-3 text-lg font-bold">5. {{ title('soci') }}</h2>
                <p v-if="form.errors['steps.soci']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.soci'] }}</p>
                <div class="grid gap-3 text-sm sm:grid-cols-2">
                    <label class="flex items-center gap-2 sm:col-span-2"><input v-model="form.steps.soci.applications_enabled" type="checkbox" /> Chi vuole iscriversi può mandare la domanda online</label>
                    <label class="flex items-center gap-2 sm:col-span-2"><input v-model="form.steps.soci.applications_rules" type="checkbox" /> Nella domanda si accetta il regolamento</label>
                    <label :class="label"><span class="font-semibold">Pagina dell'informativa privacy</span><input v-model="form.steps.soci.privacy_url" type="url" placeholder="https://" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">Pagina del regolamento</span><input v-model="form.steps.soci.rules_url" type="url" placeholder="https://" :class="field" /></label>
                    <label class="flex items-center gap-2 sm:col-span-2"><input v-model="form.steps.soci.reminders_enabled" type="checkbox" /> Manda per email i promemoria di tessera e certificato medico in scadenza</label>
                </div>
            </section>

            <section v-if="has('area_soci')" class="card p-4 sm:p-5" aria-labelledby="s-area">
                <h2 id="s-area" class="mb-3 text-lg font-bold">6. {{ title('area_soci') }}</h2>
                <p class="mb-3 text-sm text-muted">Da dare ai soci: da qui entrano nella loro area con il link o il codice che ricevono per email.</p>
                <div class="space-y-3 text-sm">
                    <div v-for="u in by.area_soci.urls" :key="u.url" class="space-y-1">
                        <span class="font-semibold">{{ u.label }}</span>
                        <div class="flex flex-wrap items-center gap-2">
                            <input :value="u.url" readonly :class="[field, 'min-w-0 flex-1']" :aria-label="u.label" @focus="$event.target.select()" />
                            <button type="button" class="btn btn-secondary" @click="copy(u.url)">{{ copied === u.url ? 'Copiato' : 'Copia' }}</button>
                        </div>
                    </div>
                </div>
            </section>

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-accent px-5 py-2.5 font-semibold text-accent-ink" :disabled="form.processing">{{ cards && cards.moved + cards.superseded > 0 ? "Salva e riallinea le tessere" : "Salva la configurazione" }}</button>
                <button v-if="!status && canSkip" type="button" class="btn btn-secondary" @click="skip">Più tardi</button>
            </div>
        </form>

        <section class="mt-8 card p-4 sm:p-5">
            <h2 class="mb-2 text-lg font-bold">E poi</h2>
            <ul class="list-disc space-y-1 pl-5 text-sm">
                <li v-if="can.people"><Link href="/gestionale/soci/importa" class="font-semibold text-accent hover:underline">Importa i soci</Link> da Excel o CSV.</li>
                <li v-if="can.operators"><Link href="/gestionale/operatori" class="font-semibold text-accent hover:underline">Invita gli altri operatori</Link> (segreteria, tesoriere, consiglieri).</li>
                <li v-if="can.people"><Link href="/gestionale/attivita/nuova" class="font-semibold text-accent hover:underline">Crea i corsi e gli eventi</Link> dell'anno.</li>
            </ul>
        </section>
    </AppLayout>
</template>
