<script setup>
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../layouts/AppLayout.vue';

const props = defineProps({ status: String, canSkip: { type: Boolean, default: true }, steps: Array, can: Object });

const by = Object.fromEntries(props.steps.map((s) => [s.key, s.props]));
const initial = {};
if (by.ente) initial.ente = { ...by.ente.values };
if (by.anno_sociale) initial.anno_sociale = { start_month: by.anno_sociale.start_month ?? '' };
if (by.anno_fiscale) initial.anno_fiscale = { start_month: by.anno_fiscale.start_month ?? '' };
if (by.tesseramento) initial.tesseramento = { fee: by.tesseramento.fee };
if (by.conti) initial.conti = { openings: Object.fromEntries(by.conti.accounts.map((a) => [a.id, a.opening])), bank_name: '', bank_opening: '' };
if (by.soci) initial.soci = { ...by.soci };
const form = useForm({ steps: initial });
const save = () => form.post('/gestionale/configurazione', { preserveScroll: true, onError: () => document.querySelector('[role=alert]')?.scrollIntoView({ block: 'center' }) });
const skip = () => router.post('/gestionale/configurazione/rimanda');

const months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
const field = 'field';
const label = 'space-y-1 text-sm';
const has = (k) => props.steps.some((s) => s.key === k);
const title = (k) => props.steps.find((s) => s.key === k)?.title;
</script>

<template>
    <Head title="Configurazione" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Configurazione guidata</h1>
        <p class="mb-6 text-sm text-muted">Le impostazioni principali in una pagina: compila quello che sai, il resto si cambia dopo. Si salva tutto insieme.</p>

        <form class="space-y-6" novalidate @submit.prevent="save">
            <section v-if="has('anno_sociale') || has('anno_fiscale')" class="card p-4 sm:p-5" aria-labelledby="s-anni">
                <h2 id="s-anni" class="mb-3 text-lg font-bold">1. Anno sociale e anno fiscale</h2>
                <p v-if="form.errors['steps.anno_sociale']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.anno_sociale'] }}</p>
                <p v-if="form.errors['steps.anno_fiscale']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.anno_fiscale'] }}</p>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label v-if="has('anno_sociale')" :class="label"><span class="font-semibold">L'anno sociale comincia a</span>
                        <select v-model.number="form.steps.anno_sociale.start_month" :class="field" required><option value="" disabled>Scegli il mese</option><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select></label>
                    <label v-if="has('anno_fiscale')" :class="label"><span class="font-semibold">L'anno fiscale comincia a</span>
                        <select v-model.number="form.steps.anno_fiscale.start_month" :class="field" required :disabled="by.anno_fiscale.locked"><option value="" disabled>Scegli il mese</option><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select></label>
                </div>
                <p class="mt-2 text-sm text-muted">Con inizio a gennaio l'anno coincide con quello solare («2026»); altrimenti è a cavallo («2025/2026»). I due anni possono cominciare in mesi diversi. Vanno scelti ora: l'anno fiscale non si cambia più dopo il primo movimento registrato.</p>
            </section>

            <section v-if="has('ente')" class="card p-4 sm:p-5" aria-labelledby="s-ente">
                <h2 id="s-ente" class="mb-3 text-lg font-bold">2. {{ title('ente') }}</h2>
                <p v-if="form.errors['steps.ente']" class="mb-3 text-sm font-semibold text-danger" role="alert">{{ form.errors['steps.ente'] }}</p>
                <div class="grid gap-4 sm:grid-cols-3">
                    <label :class="[label, 'sm:col-span-2']"><span class="font-semibold">Forma dell'ente</span>
                        <select v-model="form.steps.ente.kind" :class="field"><option value="">—</option><option v-for="k in by.ente.kinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></label>
                    <label :class="label"><span class="font-semibold">Numero RUNTS</span><input v-model="form.steps.ente.runts" :class="field" maxlength="30" /></label>
                    <label :class="label"><span class="font-semibold">Codice fiscale</span><input v-model="form.steps.ente.tax_code" :class="field" maxlength="16" /></label>
                    <label :class="label"><span class="font-semibold">Partita IVA (se c'è)</span><input v-model="form.steps.ente.vat" :class="field" maxlength="16" /></label>
                    <label :class="label"><span class="font-semibold">Telefono</span><input v-model="form.steps.ente.phone" type="tel" :class="field" maxlength="30" /></label>
                    <label :class="[label, 'sm:col-span-3']"><span class="font-semibold">Sede: indirizzo</span><input v-model="form.steps.ente.address" :class="field" maxlength="190" /></label>
                    <label :class="label"><span class="font-semibold">CAP</span><input v-model="form.steps.ente.zip" inputmode="numeric" :class="field" maxlength="5" /></label>
                    <label :class="label"><span class="font-semibold">Comune</span><input v-model="form.steps.ente.city" :class="field" maxlength="120" /></label>
                    <label :class="label"><span class="font-semibold">Provincia (sigla)</span><input v-model="form.steps.ente.province" :class="field" maxlength="2" /></label>
                    <label :class="label"><span class="font-semibold">Email</span><input v-model="form.steps.ente.email" type="email" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">PEC</span><input v-model="form.steps.ente.pec" type="email" :class="field" /></label>
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

            <div class="flex flex-wrap items-center gap-3">
                <button type="submit" class="rounded-lg bg-accent px-5 py-2.5 font-semibold text-accent-ink" :disabled="form.processing">Salva la configurazione</button>
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
