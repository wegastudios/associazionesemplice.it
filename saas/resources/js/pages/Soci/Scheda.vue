<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import Avatar from '../../components/ui/Avatar.vue';
import { ChevronLeft } from '../../components/ui/icons.js';
import AppLayout from '../../layouts/AppLayout.vue';
import PersonPicker from '../../components/PersonPicker.vue';

const props = defineProps({
    person: Object, firstFreeCard: String, types: Array, levels: Array, accounts: Array,
    socialYear: Object, settings: Object, certificateKinds: Array, consentChannels: Array, consentSources: Array, canEdit: Boolean, canSettings: Boolean,
});

const p = props.person ?? {};
const form = useForm({
    type: p.type ?? 'ordinary', first_name: p.first_name ?? '', last_name: p.last_name ?? '', email: p.email ?? '',
    phone: p.phone ?? '', card_number: p.card_number ?? '', tax_code: p.tax_code ?? '', birth_date: p.birth_date ?? '',
    birth_place: p.birth_place ?? '', address: p.address ?? '', iban: p.iban ?? '', notes: p.notes ?? '',
    level_id: p.level_id ?? '', host_person_id: '', joined_on: p.joined_on ?? '',
});
const isGuest = computed(() => form.type === 'guest');
const tab = ref('dati');
const tabs = computed(() => {
    const p = props.person;
    if (!p) return [];
    const list = [{ key: 'dati', label: 'Dati' }];
    if (!isGuest.value && p.type !== 'guardian') list.push({ key: 'tessera', label: 'Tessera' });
    if (p.certificates) list.push({ key: 'salute', label: 'Certificato' });
    if (p.minor || p.guardians.length || p.dependents.length) list.push({ key: 'famiglia', label: 'Famiglia' });
    list.push({ key: 'consensi', label: 'Consensi' });
    if ((props.canEdit && memberArea.value) || (props.canEdit && !p.left_on)) list.push({ key: 'altro', label: 'Altro' });
    return list;
});

// Area soci: link d'accesso preparato dalla segreteria (per chi non ha email: si manda su WhatsApp).
const page = usePage();
const memberArea = computed(() => (page.props.org?.packages ?? []).includes('member_area') && props.person && props.person.type !== 'guest');
const accessLink = ref('');
const accessError = ref('');
const accessDone = ref('');
const xsrf = () => decodeURIComponent(document.cookie.split('; ').find((c) => c.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? '');
const memberPost = async (what) => {
    accessError.value = '';
    accessDone.value = '';
    const res = await fetch(`/gestionale/soci/${props.person.id}/area-soci/${what}`, {
        method: 'POST', credentials: 'same-origin', headers: { Accept: 'application/json', 'X-XSRF-TOKEN': xsrf() },
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok) accessError.value = json.error ?? 'Non è stato possibile.';
    else if (what === 'link') accessLink.value = json.link;
    else accessDone.value = `Accessi chiusi: ${json.closed}.`;
};
const copyLink = () => navigator.clipboard?.writeText(accessLink.value);
const save = () => (props.person ? form.put(`/gestionale/soci/${props.person.id}`, { preserveScroll: true }) : form.post('/gestionale/soci'));

const today = new Date().toISOString().slice(0, 10);
const renew = useForm({ account_id: props.accounts[0]?.id ?? '', amount: p.fee ?? '', date: today, social_year: props.socialYear.current, method: '' });
const submitRenew = () => renew.post(`/gestionale/soci/${props.person.id}/tessera`, { preserveScroll: true });
const grant = useForm({ social_year: props.socialYear.current, reason: '' });
const submitGrant = () => grant.post(`/gestionale/soci/${props.person.id}/tessera-manuale`, { preserveScroll: true, onSuccess: () => grant.reset('reason') });
const revoke = (id) => window.confirm('Togliere la tessera di questo anno sociale?') && router.post(`/gestionale/tessere/${id}/togli`, {}, { preserveScroll: true });
const leave = useForm({ date: today, reason: '' });
const submitLeave = () => window.confirm(`Registrare l'uscita di ${props.person.first_name} ${props.person.last_name} dall'associazione?`) && leave.post(`/gestionale/soci/${props.person.id}/uscita`, { preserveScroll: true });
const settingsForm = useForm({ start_month: props.settings.start_month, fee: props.settings.fee, warn_days: props.settings.warn_days });

const guardianForm = useForm({ guardian_id: '' });
const linkGuardian = () => guardianForm.post(`/gestionale/soci/${props.person.id}/tutori`, { preserveScroll: true, onSuccess: () => guardianForm.reset() });
const makeOperative = (g) => router.post(`/gestionale/soci/${props.person.id}/tutori/${g.id}/operativo`, {}, { preserveScroll: true });
const unlinkGuardian = (g) => window.confirm(`Scollegare ${g.name}? Non vedrà più la scheda del minore nell'area soci.`) && router.delete(`/gestionale/soci/${props.person.id}/tutori/${g.id}`, { preserveScroll: true });

const nextYear = (d) => { const x = new Date(d); x.setFullYear(x.getFullYear() + 1); return x.toISOString().slice(0, 10); };
const certForm = useForm({ kind: 'non_agonistic', issued_on: today, expires_on: nextYear(today), doctor: '', file: null });
const addCert = () => certForm.post(`/gestionale/soci/${props.person.id}/certificati`, { preserveScroll: true, forceFormData: true, onSuccess: () => certForm.reset() });
const deleteCert = (c) => window.confirm('Cancellare questo certificato medico? Il file allegato viene eliminato.') && router.delete(`/gestionale/certificati/${c.id}`, { preserveScroll: true });
const certBadge = { valid: 'bg-salvia/15', expiring: 'bg-giallo/25', expired: 'bg-danger/15 text-danger', none: 'bg-surface' };
const consentForm = useForm({ channel: '', granted: true, source: 'paper', given_by: '' });
const setConsent = (channel, granted) => {
    consentForm.channel = channel;
    consentForm.granted = granted;
    if (props.person.minor && !consentForm.given_by) consentForm.given_by = props.person.guardians.find((g) => g.operative)?.id ?? '';
    consentForm.transform((d) => ({ ...d, given_by: d.given_by || null })).post(`/gestionale/soci/${props.person.id}/consensi`, { preserveScroll: true });
};
const submitSettings = () => settingsForm.post('/gestionale/soci/impostazioni', { preserveScroll: true });
const months = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];

const field = 'field';
const label = 'space-y-1 text-sm';
</script>

<template>
    <Head :title="person ? `${person.last_name} ${person.first_name}` : 'Nuova scheda'" />
    <AppLayout>
        <Link href="/gestionale/soci" class="-ml-1 mb-1 inline-flex min-h-9 items-center gap-0.5 rounded-lg pr-2 text-sm font-semibold text-accent hover:underline"><ChevronLeft class="size-4" aria-hidden="true" />Soci</Link>
        <div class="mb-4 flex items-center gap-4">
            <Avatar v-if="person" :name="`${person.first_name} ${person.last_name}`" size="lg" />
            <div class="min-w-0 flex-1">
                <h1 class="page-title">{{ person ? `${person.first_name} ${person.last_name}` : 'Nuova scheda' }}</h1>
                <p v-if="person" class="mt-1.5 flex flex-wrap gap-1.5">
                    <span v-if="person.left_on" class="badge badge-neutral">Uscito il {{ person.left_on }}</span>
                    <span v-else-if="person.active" class="badge badge-ok">In regola</span>
                    <span v-else class="badge badge-warn">Senza tessera valida</span>
                    <span v-if="person.minor" class="badge badge-neutral">Minorenne</span>
                </p>
            </div>
        </div>

        <!-- Sezioni della scheda: una alla volta, così sul telefono la pagina resta corta. -->
        <nav v-if="person" class="sticky top-14 z-20 -mx-4 mb-4 flex gap-2 overflow-x-auto bg-surface/95 px-4 py-2 backdrop-blur lg:top-0 lg:mx-0 lg:px-0" aria-label="Sezioni della scheda">
            <button v-for="t in tabs" :key="t.key" type="button" :aria-pressed="tab === t.key"
                :class="['inline-flex min-h-10 shrink-0 items-center rounded-full border px-4 text-sm font-semibold transition-colors', tab === t.key ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card text-ink hover:bg-surface']"
                @click="tab = t.key">{{ t.label }}</button>
        </nav>

        <form v-show="!person || tab === 'dati'" class="space-y-6 card p-4 sm:p-5" @submit.prevent="save">
            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-2 text-lg font-bold">Dati principali</legend>
                <label :class="label"><span class="font-semibold">Tipo</span>
                    <select v-model="form.type" :class="field"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
                <label :class="label"><span class="font-semibold">Nome</span><input v-model="form.first_name" required :class="field" /></label>
                <label :class="label"><span class="font-semibold">Cognome</span><input v-model="form.last_name" required :class="field" /></label>
                <label :class="label"><span class="font-semibold">Email</span><input v-model="form.email" type="email" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Cellulare</span><input v-model="form.phone" type="tel" :class="field" /></label>
                <label v-if="!isGuest" :class="label">
                    <span class="font-semibold">Tessera</span>
                    <span class="flex gap-2"><input v-model="form.card_number" :class="field" />
                        <button type="button" class="whitespace-nowrap text-sm font-semibold text-accent" @click="form.card_number = firstFreeCard">Primo libero ({{ firstFreeCard }})</button></span>
                </label>
                <div v-if="isGuest" class="space-y-1 text-sm sm:col-span-2">
                    <label for="host" class="font-semibold">Socio che lo ospita</label>
                    <PersonPicker id="host" v-model="form.host_person_id" />
                </div>
                <label v-if="levels.length && !isGuest" :class="label"><span class="font-semibold">Livello</span>
                    <select v-model="form.level_id" :class="field"><option value="">Nessuno</option><option v-for="l in levels" :key="l.id" :value="l.id">{{ l.name }}</option></select></label>
                <label :class="label"><span class="font-semibold">Data di ingresso</span><input v-model="form.joined_on" type="date" :class="field" /></label>
            </fieldset>

            <fieldset :disabled="!canEdit" class="grid gap-4 sm:grid-cols-3">
                <legend class="mb-1 text-lg font-bold">Dati riservati</legend>
                <p class="text-sm text-muted sm:col-span-3">Questi dati sono cifrati: nel database non si leggono.</p>
                <label :class="label"><span class="font-semibold">Codice fiscale</span><input v-model="form.tax_code" autocomplete="off" class="uppercase" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Data di nascita</span><input v-model="form.birth_date" type="date" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Luogo di nascita</span><input v-model="form.birth_place" :class="field" /></label>
                <label :class="label" class="sm:col-span-2"><span class="font-semibold">Indirizzo</span><input v-model="form.address" placeholder="Via, CAP, comune, provincia" :class="field" /></label>
                <label :class="label"><span class="font-semibold">IBAN</span><input v-model="form.iban" autocomplete="off" :class="field" /></label>
                <label :class="label" class="sm:col-span-3"><span class="font-semibold">Note</span><textarea v-model="form.notes" rows="3" :class="field" /></label>
            </fieldset>

            <ul v-if="Object.keys(form.errors).length" class="space-y-1 text-sm font-semibold text-danger" role="alert">
                <li v-for="(e, k) in form.errors" :key="k">{{ e }}</li>
            </ul>
            <button v-if="canEdit" type="submit" class="btn btn-primary" :disabled="form.processing">{{ person ? 'Salva la scheda' : 'Crea la scheda' }}</button>
        </form>

        <template v-if="person">
            <section v-if="!isGuest && person.type !== 'guardian'" v-show="tab === 'tessera'" class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-bold">Tesseramento</h2>
                <p v-if="person.type === 'founder'" class="text-sm">Socio fondatore: la tessera è sempre rinnovata.</p>
                <ul v-if="person.memberships.length" class="list-group mb-4">
                    <li v-for="m in person.memberships" :key="m.id" class="list-row" :class="m.valid ? '' : 'opacity-60'">
                        <span class="min-w-0 flex-1">
                            <span class="block font-semibold" :class="m.valid ? '' : 'line-through'">Anno sociale {{ m.social_year }}</span>
                            <span class="caption block">fino al {{ m.valid_to }} – {{ { payment: 'quota incassata', manual: 'senza incasso', import: 'importata', founder: 'fondatore' }[m.source] }}</span>
                        </span>
                        <span v-if="m.valid" class="badge badge-ok">Valida</span><span v-else class="badge badge-neutral">Tolta</span>
                        <button v-if="canEdit && m.valid && m.source !== 'payment'" type="button" class="btn btn-ghost btn-sm" @click="revoke(m.id)">Togli</button>
                    </li>
                </ul>
                <form v-if="canEdit && person.type !== 'founder' && !person.left_on" class="grid gap-3 sm:grid-cols-5" @submit.prevent="submitRenew">
                    <label :class="label"><span class="font-semibold">Anno sociale</span>
                        <select v-model="renew.social_year" :class="field"><option :value="socialYear.current">{{ socialYear.current }}</option><option :value="socialYear.next">{{ socialYear.next }}</option></select></label>
                    <label :class="label"><span class="font-semibold">Quota</span><input v-model="renew.amount" inputmode="decimal" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="renew.date" type="date" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Conto</span>
                        <select v-model="renew.account_id" :class="field"><option v-for="a in accounts" :key="a.id" :value="a.id">{{ a.name }}</option></select></label>
                    <div class="flex items-end"><button type="submit" class="btn-block btn btn-primary" :disabled="renew.processing">Incassa e rinnova</button></div>
                    <p class="text-sm text-muted sm:col-span-5">L'incasso va in prima nota con la ricevuta; stornandolo la tessera non vale più.</p>
                </form>
                <form v-if="canEdit && person.type !== 'founder' && !person.left_on" class="mt-4 flex flex-wrap items-end gap-3 border-t border-line pt-4" @submit.prevent="submitGrant">
                    <label :class="label" class="min-w-64 flex-1"><span class="font-semibold">Tessera senza incasso: motivo</span><input v-model="grant.reason" required placeholder="es. socio onorario" :class="field" /></label>
                    <button type="submit" class="btn btn-secondary">Registra la tessera {{ grant.social_year }}</button>
                </form>
            </section>

            <section v-if="person.certificates" v-show="tab === 'salute'" class="card p-4 sm:p-5">
                <div class="mb-3 flex flex-wrap items-center gap-3">
                    <h2 class="text-lg font-bold">Certificato medico</h2>
                    <span class="rounded px-2 py-0.5 text-sm font-semibold" :class="certBadge[person.certificates.status]">{{ person.certificates.label }}</span>
                </div>
                <p class="mb-3 text-sm text-muted">Dato sanitario: è cifrato e ogni consultazione resta nel registro delle azioni.</p>
                <table v-if="person.certificates.items.length" class="mb-4 w-full text-sm">
                    <thead class="text-left text-muted"><tr><th class="py-2">Tipo</th><th class="py-2">Medico</th><th class="py-2">Rilascio</th><th class="py-2">Scadenza</th><th class="py-2"></th></tr></thead>
                    <tbody>
                        <tr v-for="c in person.certificates.items" :key="c.id" class="border-t border-line" :class="c.current ? 'font-semibold' : 'text-muted'">
                            <td class="py-2">{{ c.kind }}</td><td class="py-2">{{ c.doctor ?? '' }}</td><td class="py-2">{{ c.issued_on }}</td><td class="py-2">{{ c.expires_on }}</td>
                            <td class="space-x-3 py-2 text-right">
                                <a v-if="c.has_file" :href="`/gestionale/certificati/${c.id}/file`" class="text-accent hover:underline">Scarica</a>
                                <button v-if="canEdit" type="button" class="text-danger hover:underline" @click="deleteCert(c)">Togli</button>
                            </td>
                        </tr>
                    </tbody>
                </table>
                <form v-if="canEdit" class="grid gap-3 sm:grid-cols-5" @submit.prevent="addCert">
                    <label :class="label"><span class="font-semibold">Tipo</span>
                        <select v-model="certForm.kind" :class="field"><option v-for="k in certificateKinds" :key="k.value" :value="k.value">{{ k.label }}</option></select></label>
                    <label :class="label"><span class="font-semibold">Rilascio</span><input v-model="certForm.issued_on" type="date" :class="field" required @change="certForm.expires_on = nextYear(certForm.issued_on)" /></label>
                    <label :class="label"><span class="font-semibold">Scadenza</span><input v-model="certForm.expires_on" type="date" :class="field" required /></label>
                    <label :class="label"><span class="font-semibold">Medico</span><input v-model="certForm.doctor" :class="field" /></label>
                    <label :class="label"><span class="font-semibold">File (PDF o foto)</span><input type="file" accept="application/pdf,image/jpeg,image/png" class="block w-full text-sm" @input="certForm.file = $event.target.files[0]" /></label>
                    <div class="sm:col-span-5"><button type="submit" class="btn btn-primary" :disabled="certForm.processing">Registra il certificato</button></div>
                    <p v-if="certForm.errors.file || certForm.errors.expires_on" class="text-sm text-danger sm:col-span-5">{{ certForm.errors.file || certForm.errors.expires_on }}</p>
                </form>
            </section>

            <section v-if="person.minor || person.guardians.length" v-show="tab === 'famiglia'" class="mb-4 card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-bold">Genitori o tutori</h2>
                <p v-if="!person.guardians.length" class="mb-3 text-sm text-muted">Nessun tutore collegato. Il tutore operativo segue il minore nell'area soci.</p>
                <ul class="mb-4 space-y-2 text-sm">
                    <li v-for="g in person.guardians" :key="g.id" class="flex flex-wrap items-center gap-3">
                        <Link :href="`/gestionale/soci/${g.id}`" class="font-semibold text-accent hover:underline">{{ g.name }}</Link>
                        <span class="text-muted">{{ g.email ?? 'senza email' }}</span>
                        <span v-if="g.operative" class="rounded bg-salvia/15 px-2 py-0.5">operativo</span>
                        <template v-if="canEdit">
                            <button v-if="!g.operative" type="button" class="text-accent hover:underline" @click="makeOperative(g)">Rendi operativo</button>
                            <button type="button" class="text-danger hover:underline" @click="unlinkGuardian(g)">Scollega</button>
                        </template>
                    </li>
                </ul>
                <form v-if="canEdit && person.guardians.length < 2" class="flex flex-wrap items-end gap-3" @submit.prevent="linkGuardian">
                    <div class="min-w-64 flex-1 space-y-1 text-sm"><label for="guardian" class="font-semibold">Collega un genitore o tutore</label><PersonPicker id="guardian" v-model="guardianForm.guardian_id" /></div>
                    <button type="submit" class="btn btn-secondary" :disabled="!guardianForm.guardian_id">Collega</button>
                </form>
            </section>

            <section v-show="tab === 'consensi'" class="card p-4 sm:p-5" aria-labelledby="consents-title">
                <h2 id="consents-title" class="mb-3 text-lg font-bold">Consensi</h2>
                <div v-if="canEdit" class="mb-4 flex flex-wrap gap-3 text-sm">
                    <label class="space-y-1"><span class="block">Come è stato dato</span>
                        <select v-model="consentForm.source" class="field w-auto"><option v-for="s in consentSources" :key="s.value" :value="s.value">{{ s.label }}</option></select>
                    </label>
                    <label v-if="person.minor" class="space-y-1"><span class="block">Dato da</span>
                        <select v-model="consentForm.given_by" class="field w-auto">
                            <option value="">scegli il genitore o tutore</option>
                            <option v-for="g in person.guardians" :key="g.id" :value="g.id">{{ g.name }}</option>
                        </select>
                    </label>
                </div>
                <ul class="divide-y divide-line text-sm">
                    <li v-for="c in consentChannels" :key="c.value" class="flex flex-wrap items-center gap-3 py-2">
                        <span class="min-w-48 font-semibold">{{ c.label }}</span>
                        <template v-if="person.consents[c.value]">
                            <span :class="person.consents[c.value].granted ? 'rounded bg-salvia/15 px-2 py-0.5' : 'rounded bg-surface px-2 py-0.5'">{{ person.consents[c.value].granted ? 'dato' : 'revocato' }}</span>
                            <span class="text-muted">{{ person.consents[c.value].at }} · {{ person.consents[c.value].source }}<template v-if="person.consents[c.value].given_by"> · da {{ person.consents[c.value].given_by }}</template></span>
                        </template>
                        <span v-else class="text-muted">non registrato</span>
                        <span v-if="canEdit" class="ml-auto flex gap-3">
                            <button v-if="!person.consents[c.value]?.granted" type="button" class="text-accent hover:underline" :disabled="consentForm.processing" @click="setConsent(c.value, true)">Registra consenso</button>
                            <button v-else type="button" class="text-danger hover:underline" :disabled="consentForm.processing" @click="setConsent(c.value, false)">Revoca</button>
                        </span>
                    </li>
                </ul>
            </section>

            <section v-if="person.dependents.length" v-show="tab === 'famiglia'" class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-bold">È genitore o tutore di</h2>
                <ul class="space-y-1 text-sm"><li v-for="d in person.dependents" :key="d.id"><Link :href="`/gestionale/soci/${d.id}`" class="font-semibold text-accent hover:underline">{{ d.name }}</Link></li></ul>
            </section>

            <section v-if="canEdit && memberArea" v-show="tab === 'altro'" class="mb-4 card p-4 sm:p-5" aria-labelledby="area-title">
                <h2 id="area-title" class="mb-2 text-lg font-bold">Area soci</h2>
                <p class="mb-3 text-sm text-muted">Con l'email entra da solo: riceve un link e un codice. Senza email, prepara un link d'accesso (vale 7 giorni, una volta sola) e mandalo su WhatsApp.</p>
                <div class="flex flex-wrap gap-3 text-sm">
                    <button type="button" class="btn btn-secondary" @click="memberPost('link')">Prepara il link d'accesso</button>
                    <button type="button" class="btn btn-danger" @click="memberPost('esci')">Chiudi i suoi accessi</button>
                </div>
                <div v-if="accessLink" class="mt-3 flex flex-wrap items-center gap-2">
                    <input :value="accessLink" readonly class="min-w-64 flex-1 rounded-lg border border-line bg-surface px-3 py-2 text-sm" aria-label="Link d'accesso" @focus="$event.target.select()" />
                    <button type="button" class="btn btn-primary btn-sm" @click="copyLink">Copia</button>
                </div>
                <p v-if="accessError" class="mt-2 text-sm text-danger" role="alert">{{ accessError }}</p>
                <p v-if="accessDone" class="mt-2 text-sm" role="status">{{ accessDone }}</p>
            </section>

            <section v-if="canEdit && !person.left_on" v-show="tab === 'altro'" class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-bold">Uscita dall'associazione</h2>
                <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitLeave">
                    <label :class="label"><span class="font-semibold">Data</span><input v-model="leave.date" type="date" :class="field" required /></label>
                    <label :class="label" class="min-w-64 flex-1"><span class="font-semibold">Motivo (facoltativo)</span><input v-model="leave.reason" :class="field" /></label>
                    <button type="submit" class="btn btn-secondary">Registra l'uscita</button>
                </form>
            </section>
        </template>

        <section v-if="canSettings && !person" class="mt-6 card p-4 sm:p-5">
            <h2 class="mb-3 text-lg font-bold">Impostazioni del tesseramento</h2>
            <form class="flex flex-wrap items-end gap-3" @submit.prevent="submitSettings">
                <label :class="label"><span class="font-semibold">L'anno sociale inizia a</span>
                    <select v-model="settingsForm.start_month" :class="field"><option v-for="(m, i) in months" :key="m" :value="i + 1">{{ m }}</option></select></label>
                <label :class="label"><span class="font-semibold">Quota associativa</span><input v-model="settingsForm.fee" inputmode="decimal" :class="field" /></label>
                <label :class="label"><span class="font-semibold">Preavviso certificati (giorni)</span><input v-model="settingsForm.warn_days" type="number" min="1" max="365" :class="field" /></label>
                <button type="submit" class="btn btn-secondary">Salva</button>
            </form>
        </section>
    </AppLayout>
</template>
