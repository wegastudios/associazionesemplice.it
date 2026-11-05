<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '../../components/BrandLogo.vue';

const props = defineProps({ settings: Object, warnings: Array, effective: Object });
const page = usePage();
const status = computed(() => page.props.flash?.status);
const error = computed(() => page.props.errors?.ledger);

const sections = [
    { key: 'mail', title: 'Posta', hint: 'Il server SMTP da cui partono inviti, promemoria e ricevute. Per Associazione Semplice: mail.associazionesemplice.it, porta 587 con tls.' },
    { key: 'backup', title: 'Spazio dei backup (SFTP)', hint: 'Dove vanno i dump orari cifrati: uno spazio esterno in UE. Senza, i dump restano sul server.' },
    { key: 'keys', title: 'Servizio chiavi', hint: 'L\'account separato che custodisce la chiave principale. Senza, la piattaforma usa la chiave locale ed è solo di prova: il portale non può creare associazioni vere.' },
    { key: 'cache', title: 'Cache', hint: 'Con «auto» si usa Redis se c\'è, altrimenti il database.' },
];
const field = (k) => k.replace('.', '__');
const forms = Object.fromEntries(sections.map((s) => {
    const data = { clear: [] };
    Object.entries(props.settings).filter(([k]) => k.startsWith(`${s.key}.`)).forEach(([k, v]) => { data[field(k)] = v.value; });
    return [s.key, useForm(data)];
}));
const keysOf = (s) => Object.entries(props.settings).filter(([k]) => k.startsWith(`${s}.`));
const save = (s) => forms[s].post(`/admin/piattaforma/${s}`, { preserveScroll: true, onSuccess: () => { forms[s].clear = []; } });
const test = (s) => router.post(`/admin/piattaforma/${s}/prova`, {}, { preserveScroll: true });
const input = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Amministrazione della piattaforma" />
    <div class="min-h-full">
        <header class="border-b border-line bg-card">
            <div class="mx-auto flex max-w-4xl items-center gap-4 px-4 py-3">
                <BrandLogo />
                <p class="flex-1 font-bold">Amministrazione della piattaforma</p>
                <Link href="/associazioni" class="text-sm font-semibold text-accent hover:underline">Associazioni</Link>
                <Link href="/esci" method="post" as="button" class="text-sm font-semibold text-accent hover:underline">Esci</Link>
            </div>
        </header>
        <main class="mx-auto max-w-4xl px-4 py-6">
            <p v-if="status" class="mb-4 rounded-lg border border-line bg-surface px-4 py-3 text-sm font-semibold" role="status">{{ status }}</p>
            <p v-if="error" class="mb-4 rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-semibold text-danger" role="alert">{{ error }}</p>
            <section v-if="warnings.length" class="mb-6 rounded-2xl border border-giallo/60 bg-giallo/10 p-4 text-sm" aria-label="Ripieghi in uso">
                <p class="mb-1 font-semibold">Ripieghi in uso</p>
                <ul class="list-disc pl-5"><li v-for="w in warnings" :key="w">{{ w }}</li></ul>
            </section>
            <p class="mb-6 text-sm text-muted">Ogni valore qui prende il posto di quello del file d'ambiente sul server. I segreti si salvano cifrati e non si rileggono: un campo segreto lasciato vuoto resta com'è.</p>

            <section v-for="s in sections" :key="s.key" class="mb-6 rounded-2xl border border-line bg-card p-5" :aria-labelledby="`s-${s.key}`">
                <div class="mb-1 flex flex-wrap items-center gap-3">
                    <h2 :id="`s-${s.key}`" class="text-lg font-bold">{{ s.title }}</h2>
                    <span class="rounded bg-surface px-2 py-0.5 text-xs">ora: {{ effective[s.key] }}</span>
                </div>
                <p class="mb-4 text-sm text-muted">{{ s.hint }}</p>
                <form class="grid gap-4 text-sm sm:grid-cols-2" @submit.prevent="save(s.key)">
                    <label v-for="[k, v] in keysOf(s.key)" :key="k" class="space-y-1" :class="k.endsWith('private_key') ? 'sm:col-span-2' : ''">
                        <span class="block font-semibold">{{ v.label }}<span v-if="v.secret && v.set" class="ml-2 rounded bg-salvia/15 px-1.5 py-0.5 text-xs font-normal">impostato</span></span>
                        <textarea v-if="k.endsWith('private_key')" v-model="forms[s.key][field(k)]" rows="4" :class="input" autocomplete="off" spellcheck="false" :placeholder="v.set ? 'lascia vuoto per non cambiarla' : '-----BEGIN OPENSSH PRIVATE KEY-----'" />
                        <input v-else v-model="forms[s.key][field(k)]" :type="v.secret ? 'password' : 'text'" :class="input" autocomplete="off" :placeholder="v.secret && v.set ? 'lascia vuoto per non cambiarlo' : ''" />
                        <label v-if="v.secret && v.set" class="flex items-center gap-2 text-xs text-muted"><input v-model="forms[s.key].clear" type="checkbox" :value="field(k)" /> togli</label>
                    </label>
                    <div class="flex flex-wrap gap-3 sm:col-span-2">
                        <button type="submit" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="forms[s.key].processing">Salva</button>
                        <button type="button" class="rounded-lg border border-line px-4 py-2 font-semibold" @click="test(s.key)">{{ s.key === 'mail' ? 'Manda un\'email di prova' : 'Prova' }}</button>
                    </div>
                </form>
            </section>
        </main>
    </div>
</template>
