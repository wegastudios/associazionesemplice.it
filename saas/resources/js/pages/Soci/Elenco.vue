<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ q: String, type: String, types: Array, year: String, rows: Array, next: String, prev: String, activeCount: Number, canEdit: Boolean, canExport: Boolean });

const search = ref(props.q ?? '');
let timer = null;
const filter = (changes) => router.get('/gestionale/soci', { q: search.value, tipo: props.type, ...changes }, { preserveState: true, replace: true });
const onSearch = () => {
    clearTimeout(timer);
    timer = setTimeout(() => filter({ q: search.value }), 300);
};
const exportUrl = () => `/gestionale/soci/esporta?${new URLSearchParams({ q: search.value ?? '', tipo: props.type ?? '' })}`;
const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head title="Soci" />
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Soci</h1>
                <p class="text-sm text-muted">{{ activeCount }} soci attivi · anno sociale {{ year }}</p>
            </div>
            <div class="flex flex-wrap items-center gap-3 text-sm font-semibold">
                <Link href="/gestionale/certificati" class="text-accent hover:underline">Certificati medici</Link>
                <Link href="/gestionale/soci/livelli" class="text-accent hover:underline">Livelli</Link>
                <Link v-if="canEdit" href="/gestionale/soci/importa" class="text-accent hover:underline">Importa</Link>
                <a v-if="canExport" :href="exportUrl()" class="text-accent hover:underline">Esporta (CSV)</a>
                <Link v-if="canEdit" href="/gestionale/soci/nuovo" class="rounded-lg bg-accent px-4 py-2 text-base text-accent-ink">Nuova scheda</Link>
            </div>
        </div>

        <div class="mb-4 grid gap-3 sm:grid-cols-[2fr_1fr]">
            <label class="space-y-1 text-sm">
                <span class="font-semibold">Cerca</span>
                <input v-model="search" type="search" placeholder="Nome, cognome, tessera, email o codice fiscale esatto" :class="field" @input="onSearch" />
            </label>
            <label class="space-y-1 text-sm">
                <span class="font-semibold">Tipo</span>
                <select :class="field" :value="type" @change="filter({ tipo: $event.target.value })">
                    <option value="">Tutti</option>
                    <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                </select>
            </label>
        </div>

        <div class="overflow-x-auto rounded-2xl border border-line bg-card">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr><th class="px-4 py-3">Tessera</th><th class="px-4 py-3">Nome</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3">Email</th><th class="px-4 py-3">Stato</th></tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="5" class="px-4 py-6 text-center text-muted">Nessuna scheda trovata.</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line hover:bg-surface">
                        <td class="px-4 py-3 tabular-nums">{{ r.card ?? '—' }}</td>
                        <td class="px-4 py-3 font-semibold"><Link :href="`/gestionale/soci/${r.id}`" class="text-accent hover:underline">{{ r.name }}</Link></td>
                        <td class="px-4 py-3">{{ r.type }}</td>
                        <td class="px-4 py-3">{{ r.email ?? '' }}</td>
                        <td class="px-4 py-3">
                            <span v-if="r.left" class="text-muted">Uscito</span>
                            <span v-else-if="r.active" class="rounded bg-salvia/15 px-2 py-0.5 font-semibold">In regola</span>
                            <span v-else class="rounded bg-giallo/20 px-2 py-0.5">Senza tessera</span>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <nav class="mt-4 flex justify-between text-sm font-semibold" aria-label="Pagine">
            <Link v-if="prev" :href="prev" class="text-accent">← Precedenti</Link><span v-else />
            <Link v-if="next" :href="next" class="text-accent">Successivi →</Link>
        </nav>
    </AppLayout>
</template>
