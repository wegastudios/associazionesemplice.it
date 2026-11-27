<script setup>
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import MemberLayout from '../../layouts/MemberLayout.vue';

const props = defineProps({ card: Object, details: Object, member: Object, twoFactor: Object });
const form = useForm({ phone: props.details.phone ?? '', address: props.details.address ?? '' });
const own = props.member.profiles.find((p) => p.id === props.member.active)?.own ?? true;
const field = 'field';

// Verifica in due passaggi della scheda di chi è entrato. Se l'associazione la chiede e non è accesa, si vede solo questa.
const tfa = computed(() => props.twoFactor);
const mustSetUp = computed(() => tfa.value.required && !tfa.value.on);
const turnOff = () => window.confirm('Spegnere la verifica in due passaggi? Per entrare basterà di nuovo il link o il codice via email.')
    && off.post('/area/profilo/verifica/spegni', { preserveScroll: true, onFinish: () => off.reset('code') });
const start = useForm({});
const confirmForm = useForm({ code: '' });
const regen = useForm({ code: '' });
const off = useForm({ code: '' });
const cancel = useForm({});
const tfaError = computed(() => usePage().props.errors?.tfa ?? null);
</script>

<template>
    <Head title="Profilo" />
    <MemberLayout>
        <h1 class="mb-4 text-2xl font-bold">{{ card.first_name }} {{ card.last_name }}</h1>

        <section v-if="own" id="verifica" class="mb-6 card p-4 sm:p-5 text-sm" aria-labelledby="tfa-title">
            <h2 id="tfa-title" class="mb-2 text-lg font-bold">Verifica in due passaggi</h2>
            <p v-if="mustSetUp" class="mb-3 rounded-lg border border-giallo bg-giallo/15 px-3 py-2 font-semibold" role="alert">
                L'associazione chiede la verifica in due passaggi ai soci: accendila per continuare a usare l'area soci.
            </p>
            <p class="mb-3 text-muted">Oltre al link o al codice via email, per entrare ti chiediamo un codice che cambia ogni 30 secondi, da un'app sul telefono (per esempio Google Authenticator o Microsoft Authenticator).</p>
            <p v-if="tfaError" class="mb-3 font-semibold text-danger" role="alert">{{ tfaError }}</p>

            <div v-if="tfa.codes" class="mb-4 rounded-lg border border-salvia/40 bg-salvia/10 p-3">
                <p class="mb-2"><strong>I tuoi codici di riserva.</strong> Scrivili o stampali e tienili in un posto sicuro: li vedi solo adesso. Se perdi il telefono, ognuno ti fa entrare una volta al posto del codice dell'app.</p>
                <ul class="grid grid-cols-2 gap-1 font-mono sm:grid-cols-5"><li v-for="c in tfa.codes" :key="c">{{ c }}</li></ul>
            </div>

            <template v-if="tfa.on">
                <p class="mb-3">È <strong>accesa</strong>. Codici di riserva ancora da usare: <strong>{{ tfa.backups }}</strong>.</p>
                <details class="mb-2">
                    <summary class="cursor-pointer font-semibold">Nuovi codici di riserva</summary>
                    <p class="my-2 text-muted">I codici di riserva che hai adesso smettono di valere.</p>
                    <form class="flex flex-wrap items-end gap-2" @submit.prevent="regen.post('/area/profilo/verifica/codici', { preserveScroll: true, onFinish: () => regen.reset('code') })">
                        <label class="space-y-1"><span class="block font-semibold">Codice dell'app</span><input v-model="regen.code" inputmode="numeric" maxlength="20" autocomplete="one-time-code" required :class="field" /></label>
                        <button type="submit" class="btn btn-secondary" :disabled="regen.processing">Crea nuovi codici</button>
                    </form>
                </details>
                <details v-if="!tfa.required">
                    <summary class="cursor-pointer font-semibold">Spegni la verifica in due passaggi</summary>
                    <form class="mt-2 flex flex-wrap items-end gap-2" @submit.prevent="turnOff">
                        <label class="space-y-1"><span class="block font-semibold">Codice dell'app o di riserva</span><input v-model="off.code" inputmode="numeric" maxlength="20" autocomplete="one-time-code" required :class="field" /></label>
                        <button type="submit" class="btn btn-danger" :disabled="off.processing">Spegni</button>
                    </form>
                </details>
                <p v-else class="text-muted">L'associazione la chiede ai soci: non si può spegnere.</p>
            </template>

            <template v-else-if="tfa.pending && tfa.setup">
                <ol class="mb-3 list-decimal space-y-1 pl-5">
                    <li>Apri l'app di autenticazione sul telefono e inquadra il codice QR.</li>
                    <li>Scrivi qui il codice di 6 cifre che compare nell'app.</li>
                </ol>
                <div class="mb-3 w-fit rounded-lg bg-white p-3" aria-hidden="true" v-html="tfa.setup.qr_svg" />
                <p class="mb-3 text-muted">Non riesci a inquadrarlo? Chiave da copiare: <code class="break-all rounded bg-surface px-1 font-mono text-ink">{{ tfa.setup.secret }}</code></p>
                <form class="flex flex-wrap items-end gap-2" @submit.prevent="confirmForm.post('/area/profilo/verifica/conferma', { preserveScroll: true, onFinish: () => confirmForm.reset('code') })">
                    <label class="space-y-1"><span class="block font-semibold">Codice di 6 cifre dell'app</span><input v-model="confirmForm.code" inputmode="numeric" maxlength="6" autocomplete="one-time-code" required :class="field" /></label>
                    <button type="submit" class="btn btn-primary" :disabled="confirmForm.processing">Conferma e accendi</button>
                    <button type="button" class="btn btn-secondary" :disabled="cancel.processing" @click="cancel.post('/area/profilo/verifica/annulla', { preserveScroll: true })">Annulla</button>
                </form>
            </template>

            <template v-else>
                <p class="mb-3">È <strong>spenta</strong>.</p>
                <form @submit.prevent="start.post('/area/profilo/verifica/accendi', { preserveScroll: true })">
                    <button type="submit" class="btn btn-primary" :disabled="start.processing">Accendi la verifica in due passaggi</button>
                </form>
            </template>
        </section>

        <template v-if="!mustSetUp">
        <section class="mb-6 card p-4 sm:p-5" aria-labelledby="d">
            <h2 id="d" class="mb-3 text-lg font-bold">I tuoi dati</h2>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">Email</dt><dd>{{ details.email ?? '—' }}</dd></div>
                <div><dt class="text-muted">Codice fiscale</dt><dd>{{ details.tax_code ?? '—' }}</dd></div>
                <div><dt class="text-muted">Nascita</dt><dd>{{ details.birth_date ?? '—' }}<template v-if="details.birth_place"> a {{ details.birth_place }}</template></dd></div>
                <div><dt class="text-muted">Socio dal</dt><dd>{{ card.joined_on ?? '—' }}</dd></div>
                <div v-if="details.guardians.length" class="sm:col-span-2"><dt class="text-muted">Genitori o tutori</dt><dd>{{ details.guardians.join(', ') }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-muted">Per correggere nome, email o codice fiscale scrivi alla segreteria.</p>
        </section>
        <form class="mb-6 card p-4 sm:p-5" @submit.prevent="form.put('/area/profilo', { preserveScroll: true })">
            <h2 class="mb-3 text-lg font-bold">Recapiti</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm"><span class="font-semibold">Telefono</span><input v-model="form.phone" type="tel" autocomplete="tel" maxlength="40" :class="field" /></label>
                <label class="space-y-1 text-sm sm:col-span-2"><span class="font-semibold">Indirizzo</span><input v-model="form.address" autocomplete="street-address" maxlength="255" :class="field" /></label>
            </div>
            <button type="submit" class="mt-4 btn btn-primary" :disabled="form.processing">Salva</button>
        </form>
        <section v-if="details.memberships.length" class="mb-6 card p-4 sm:p-5" aria-labelledby="m">
            <h2 id="m" class="mb-3 text-lg font-bold">Tessere</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="m in details.memberships" :key="m.social_year + m.valid_to" class="flex justify-between py-2" :class="m.valid ? '' : 'text-muted line-through'">
                    <span>Tessera {{ m.social_year }}</span><span>fino al {{ m.valid_to }}</span></li>
            </ul>
        </section>
        <section v-if="own" class="card p-4 sm:p-5 text-sm">
            <h2 class="mb-2 text-lg font-bold">Accessi</h2>
            <p class="mb-3 text-muted">Hai perso il telefono o hai usato un computer non tuo? Esci da tutti i dispositivi.</p>
            <Link href="/area/esci-ovunque" method="post" as="button" class="btn btn-danger">Esci da tutti i dispositivi</Link>
        </section>
        </template>
    </MemberLayout>
</template>
