<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();
const member = computed(() => page.props.member);
const status = computed(() => page.props.flash?.status);
const error = computed(() => page.props.errors?.ledger);
const sections = computed(() => [
    { href: '/area', label: 'Tessera' },
    { href: '/area/eventi', label: 'Eventi' },
    ...(member.value?.resources ? [{ href: '/area/risorse', label: 'Prenota' }] : []),
    { href: '/area/paga', label: 'Paga' },
    { href: '/area/quote', label: 'Ricevute' },
    { href: '/area/profilo', label: 'Profilo' },
]);
const path = computed(() => page.url.split('?')[0]);
const current = (href) => (href === '/area' ? path.value === '/area' : path.value.startsWith(href));
const others = computed(() => (member.value?.profiles ?? []).length > 1);
const open = (id) => router.post(`/area/scheda/${id}`);
</script>

<template>
    <div class="min-h-full pb-20 sm:pb-0">
        <header class="border-b border-line bg-card">
            <div class="mx-auto flex max-w-3xl items-center gap-3 px-4 py-3">
                <div class="min-w-0 flex-1">
                    <p class="truncate font-bold">{{ member?.org }}</p>
                    <p class="text-sm text-muted">Area soci</p>
                </div>
                <Link href="/area/esci" method="post" as="button" class="text-sm font-semibold text-accent hover:underline">Esci</Link>
            </div>
            <nav class="mx-auto hidden max-w-3xl gap-1 px-4 pb-2 text-sm font-semibold sm:flex" aria-label="Area soci">
                <Link v-for="s in sections" :key="s.href" :href="s.href" class="rounded-lg px-3 py-2 hover:bg-surface"
                    :class="current(s.href) ? 'bg-surface text-accent' : 'text-muted'" :aria-current="current(s.href) ? 'page' : undefined">{{ s.label }}</Link>
            </nav>
        </header>
        <main class="mx-auto max-w-3xl px-4 py-6">
            <div v-if="others" class="mb-4 flex flex-wrap items-center gap-2 text-sm">
                <span class="text-muted">Scheda:</span>
                <button v-for="p in member.profiles" :key="p.id" type="button" class="rounded-full border px-3 py-1"
                    :class="p.id === member.active ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card'"
                    :aria-pressed="p.id === member.active" @click="open(p.id)">{{ p.name }}</button>
            </div>
            <p v-if="status" class="mb-4 rounded-lg border border-salvia/40 bg-salvia/10 px-3 py-2 text-sm font-semibold" role="status">{{ status }}</p>
            <p v-if="error" class="mb-4 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-sm font-semibold text-danger" role="alert">{{ error }}</p>
            <slot />
        </main>
        <nav class="fixed inset-x-0 bottom-0 grid auto-cols-fr grid-flow-col border-t border-line bg-card text-xs font-semibold sm:hidden" aria-label="Area soci">
            <Link v-for="s in sections" :key="s.href" :href="s.href" class="px-1 py-3 text-center"
                :class="current(s.href) ? 'text-accent' : 'text-muted'" :aria-current="current(s.href) ? 'page' : undefined">{{ s.label }}</Link>
        </nav>
    </div>
</template>
