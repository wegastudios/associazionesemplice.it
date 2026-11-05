<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const org = computed(() => page.props.org);
const status = computed(() => page.props.flash?.status);
const error = computed(() => page.props.errors?.ledger);

const all = [
    { href: '/gestionale', label: 'Riepilogo' },
    { href: '/gestionale/soci', label: 'Soci', can: 'people.view' },
    { href: '/gestionale/domande', label: 'Domande', can: 'people.view' },
    { href: '/gestionale/attivita', label: 'Attività', can: 'people.view' },
    { href: '/gestionale/risorse', label: 'Risorse', can: 'people.view', pkg: 'resources' },
    { href: '/gestionale/prima-nota', label: 'Prima nota', can: 'accounting.view' },
    { href: '/gestionale/conti', label: 'Conti', can: 'accounting.view' },
    { href: '/gestionale/pagamenti-online', label: 'Pagamenti online', can: 'accounting.view', pkg: 'member_area' },
    { href: '/gestionale/rendiconto', label: 'Rendiconto', can: 'accounting.view' },
    { href: '/gestionale/operatori', label: 'Operatori', can: 'org.operators' },
];
const sections = computed(() => all.filter((s) => (!s.can || (org.value?.can ?? []).includes(s.can)) && (!s.pkg || (org.value?.packages ?? []).includes(s.pkg))));
const current = (href) => (href === '/gestionale' ? page.url === href : page.url.startsWith(href));
</script>

<template>
    <div class="min-h-full">
        <header class="border-b border-line bg-card">
            <div class="mx-auto flex max-w-6xl flex-wrap items-center gap-4 px-4 py-3">
                <BrandLogo />
                <div v-if="org" class="min-w-0 flex-1">
                    <p class="truncate font-bold">{{ org.name }}</p>
                    <p class="text-sm text-muted">{{ org.role }}</p>
                </div>
                <nav class="ml-auto flex items-center gap-4 text-sm font-semibold" aria-label="Account">
                    <Link href="/associazioni" class="text-accent hover:underline">Cambia associazione</Link>
                    <Link href="/account/verifica-in-due-passaggi" class="text-accent hover:underline">Sicurezza</Link>
                    <Link href="/esci" method="post" as="button" class="text-accent hover:underline">Esci</Link>
                </nav>
            </div>
            <nav class="mx-auto flex max-w-6xl gap-1 overflow-x-auto px-4 pb-2 text-sm font-semibold" aria-label="Gestionale">
                <Link
                    v-for="s in sections"
                    :key="s.href"
                    :href="s.href"
                    class="whitespace-nowrap rounded-lg px-3 py-2 hover:bg-surface"
                    :class="current(s.href) ? 'bg-surface text-accent' : 'text-muted'"
                    :aria-current="current(s.href) ? 'page' : undefined"
                >{{ s.label }}</Link>
            </nav>
        </header>
        <main class="mx-auto max-w-6xl px-4 py-8">
            <p v-if="user" class="sr-only">Collegato come {{ user.name }}</p>
            <p v-if="status" class="mb-4 rounded-lg border border-salvia/40 bg-salvia/10 px-3 py-2 text-sm font-semibold" role="status">{{ status }}</p>
            <p v-if="error" class="mb-4 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm font-semibold text-danger" role="alert">{{ error }}</p>
            <slot />
        </main>
    </div>
</template>
