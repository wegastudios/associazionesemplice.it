<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import BrandLogo from '../../components/BrandLogo.vue';

const props = defineProps({ users: Array, orgs: Array });
const page = usePage();
const status = computed(() => page.props.flash?.status);
const error = computed(() => page.props.errors?.users);

const filter = ref('tutti');
const query = ref('');
const orgStates = { active: 'Attiva', suspended: 'Sospesa', closed: 'Chiusa' };
const roles = { owner: 'titolare', admin: 'amministratore', operator: 'segreteria', accountant: 'commercialista' };

const shownUsers = computed(() => props.users.filter((u) => {
    if (filter.value === 'attivi' && u.disabled) return false;
    if (filter.value === 'disattivati' && !u.disabled) return false;
    const q = query.value.trim().toLowerCase();
    return q === '' || u.email.toLowerCase().includes(q) || u.name.toLowerCase().includes(q);
}));
const counts = computed(() => ({
    tutti: props.users.length,
    attivi: props.users.filter((u) => !u.disabled).length,
    disattivati: props.users.filter((u) => u.disabled).length,
}));

const act = (url, data = {}, ask = null) => {
    if (ask && !window.confirm(ask)) return;
    router.post(url, data, { preserveScroll: true });
};
const input = 'rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Utenti e associazioni" />
    <div class="min-h-full">
        <header class="border-b border-line bg-card">
            <div class="mx-auto flex max-w-5xl items-center gap-4 px-4 py-3">
                <BrandLogo />
                <p class="flex-1 font-bold">Utenti e associazioni</p>
                <Link href="/admin/piattaforma" class="text-sm font-semibold text-accent hover:underline">Impostazioni</Link>
                <Link href="/associazioni" class="text-sm font-semibold text-accent hover:underline">Associazioni</Link>
                <Link href="/esci" method="post" as="button" class="text-sm font-semibold text-accent hover:underline">Esci</Link>
            </div>
        </header>
        <main class="mx-auto max-w-5xl px-4 py-6">
            <p v-if="status" class="mb-4 rounded-lg border border-line bg-surface px-4 py-3 text-sm font-semibold" role="status">{{ status }}</p>
            <p v-if="error" class="mb-4 rounded-lg border border-danger/40 bg-danger/10 px-4 py-3 text-sm font-semibold text-danger" role="alert">{{ error }}</p>

            <section class="mb-8" aria-labelledby="t-orgs">
                <h2 id="t-orgs" class="mb-3 text-lg font-bold">Associazioni ({{ orgs.length }})</h2>
                <div class="overflow-x-auto rounded-2xl border border-line bg-card">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-muted">
                            <tr><th class="px-4 py-2">Nome</th><th class="px-4 py-2">Indirizzo</th><th class="px-4 py-2">Stato</th><th class="px-4 py-2">Operatori</th><th class="px-4 py-2">Dal</th><th class="px-4 py-2"><span class="sr-only">Azioni</span></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="o in orgs" :key="o.id" class="border-b border-line last:border-0">
                                <td class="px-4 py-2 font-semibold">{{ o.name }}</td>
                                <td class="px-4 py-2 text-muted">{{ o.slug }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-xs font-semibold" :class="o.status === 'active' ? 'bg-salvia/15' : 'bg-giallo/20'">{{ orgStates[o.status] ?? o.status }}</span></td>
                                <td class="px-4 py-2">{{ o.operators }}</td>
                                <td class="px-4 py-2 text-muted">{{ o.created_at }}</td>
                                <td class="px-4 py-2 text-right">
                                    <button v-if="o.status === 'active'" type="button" class="font-semibold text-accent hover:underline" @click="act(`/admin/piattaforma/associazioni/${o.id}/stato`, { status: 'suspended' }, `Sospendere «${o.name}»? I suoi operatori non potranno più usare il gestionale.`)">Sospendi</button>
                                    <button v-else-if="o.status === 'suspended'" type="button" class="font-semibold text-accent hover:underline" @click="act(`/admin/piattaforma/associazioni/${o.id}/stato`, { status: 'active' })">Riattiva</button>
                                </td>
                            </tr>
                            <tr v-if="orgs.length === 0"><td colspan="6" class="px-4 py-4 text-muted">Nessuna associazione.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section aria-labelledby="t-users">
                <h2 id="t-users" class="mb-3 text-lg font-bold">Utenti</h2>
                <div class="mb-3 flex flex-wrap items-center gap-3 text-sm">
                    <div class="flex gap-2" role="group" aria-label="Filtro">
                        <button v-for="f in ['tutti', 'attivi', 'disattivati']" :key="f" type="button" class="rounded-lg border px-3 py-1.5 font-semibold" :class="filter === f ? 'border-accent bg-accent text-accent-ink' : 'border-line'" :aria-pressed="filter === f" @click="filter = f">{{ f[0].toUpperCase() + f.slice(1) }} ({{ counts[f] }})</button>
                    </div>
                    <input v-model="query" type="search" placeholder="Cerca per nome o email" aria-label="Cerca per nome o email" :class="input" class="min-w-60 flex-1" />
                </div>
                <div class="overflow-x-auto rounded-2xl border border-line bg-card">
                    <table class="w-full text-left text-sm">
                        <thead class="border-b border-line text-muted">
                            <tr><th class="px-4 py-2">Utente</th><th class="px-4 py-2">Associazioni</th><th class="px-4 py-2">Due passaggi</th><th class="px-4 py-2">Stato</th><th class="px-4 py-2"><span class="sr-only">Azioni</span></th></tr>
                        </thead>
                        <tbody>
                            <tr v-for="u in shownUsers" :key="u.id" class="border-b border-line align-top last:border-0">
                                <td class="px-4 py-2">
                                    <p class="font-semibold">{{ u.name }}<span v-if="u.platform_admin" class="ml-2 rounded bg-accent/15 px-1.5 py-0.5 text-xs">piattaforma</span></p>
                                    <p class="text-muted">{{ u.email }}</p>
                                </td>
                                <td class="px-4 py-2"><p v-for="m in u.orgs" :key="m.org">{{ m.org }} <span class="text-muted">· {{ roles[m.role] ?? m.role }}<template v-if="m.status !== 'active'"> · {{ orgStates[m.status] ?? m.status }}</template></span></p><span v-if="u.orgs.length === 0" class="text-muted">—</span></td>
                                <td class="px-4 py-2">{{ u.two_factor ? 'attivi' : 'no' }}</td>
                                <td class="px-4 py-2"><span class="rounded px-2 py-0.5 text-xs font-semibold" :class="u.disabled ? 'bg-danger/15 text-danger' : 'bg-salvia/15'">{{ u.disabled ? 'Disattivato' : 'Attivo' }}</span></td>
                                <td class="px-4 py-2 text-right">
                                    <button v-if="!u.disabled" type="button" class="font-semibold text-accent hover:underline" @click="act(`/admin/piattaforma/utenti/${u.id}/disattiva`, {}, `Disattivare ${u.email}? Non potrà più entrare e le sue sessioni si chiudono.`)">Disattiva</button>
                                    <button v-else type="button" class="font-semibold text-accent hover:underline" @click="act(`/admin/piattaforma/utenti/${u.id}/riattiva`)">Riattiva</button>
                                </td>
                            </tr>
                            <tr v-if="shownUsers.length === 0"><td colspan="5" class="px-4 py-4 text-muted">Nessun utente.</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </main>
    </div>
</template>
