<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';
import Avatar from '../../components/ui/Avatar.vue';
import EmptyState from '../../components/ui/EmptyState.vue';
import PageHeader from '../../components/ui/PageHeader.vue';
import Sheet from '../../components/ui/Sheet.vue';
import { BookOpen, ChevronRight, Download, Ellipsis, FileText, ListChecks, Search, Upload, UserRound } from '../../components/ui/icons.js';

const props = defineProps({ q: String, type: String, status: String, types: Array, year: String, rows: Array, next: String, prev: String, activeCount: Number, canEdit: Boolean, canExport: Boolean });

const search = ref(props.q ?? '');
const tools = ref(false);
let timer = null;
const filter = (changes) => router.get('/gestionale/soci', { q: search.value, tipo: props.type, stato: props.status, ...changes }, { preserveState: true, replace: true });
const onSearch = () => {
    clearTimeout(timer);
    timer = setTimeout(() => filter({ q: search.value }), 300);
};
const exportUrl = () => `/gestionale/soci/esporta?${new URLSearchParams({ q: search.value ?? '', tipo: props.type ?? '' })}`;
const chip = (on) => ['inline-flex min-h-10 shrink-0 items-center rounded-full border px-4 text-sm font-semibold transition-colors', on ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card text-ink hover:bg-surface'];
</script>

<template>
    <Head title="Soci" />
    <AppLayout>
        <PageHeader title="Soci" :subtitle="`${activeCount} ${activeCount === 1 ? 'socio attivo' : 'soci attivi'}, anno sociale ${year}`">
            <template #actions>
                <Link v-if="canEdit" href="/gestionale/soci/nuovo" class="btn btn-primary hidden lg:inline-flex">Nuova scheda</Link>
            </template>
        </PageHeader>

        <div class="mb-3 flex gap-2">
            <label class="relative min-w-0 flex-1">
                <span class="sr-only">Cerca un socio</span>
                <Search class="pointer-events-none absolute left-3.5 top-1/2 size-5 -translate-y-1/2 text-muted" aria-hidden="true" />
                <input v-model="search" type="search" enterkeyhint="search" autocomplete="off" placeholder="Cerca un socio" class="field pl-11" @input="onSearch" />
            </label>
            <button type="button" class="btn btn-secondary shrink-0 px-3.5" aria-label="Altri strumenti" @click="tools = true"><Ellipsis class="size-5" aria-hidden="true" /></button>
        </div>

        <div class="-mx-4 mb-2 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:px-0" role="group" aria-label="Stato della tessera">
            <button v-for="s in [['', 'Tutti'], ['in_regola', 'In regola'], ['da_rinnovare', 'Da rinnovare'], ['usciti', 'Usciti']]" :key="s[0]" type="button" :class="chip((status ?? '') === s[0])" :aria-pressed="(status ?? '') === s[0]" @click="filter({ stato: s[0] })">{{ s[1] }}</button>
        </div>

        <div class="-mx-4 mb-4 flex gap-2 overflow-x-auto px-4 pb-1 lg:mx-0 lg:px-0" role="group" aria-label="Tipo di scheda">
            <button type="button" :class="chip(!type)" :aria-pressed="!type" @click="filter({ tipo: '' })">Tutti</button>
            <button v-for="t in types" :key="t.value" type="button" :class="chip(type === t.value)" :aria-pressed="type === t.value" @click="filter({ tipo: t.value })">{{ t.label }}</button>
        </div>

        <ul v-if="rows.length" class="list-group">
            <li v-for="r in rows" :key="r.id">
                <Link :href="`/gestionale/soci/${r.id}`" class="list-row">
                    <Avatar :name="r.name" />
                    <span class="min-w-0 flex-1">
                        <span class="block truncate font-semibold">{{ r.name }}</span>
                        <span class="caption block truncate"><template v-if="r.card">n. {{ r.card }} – </template>{{ r.type }}<template v-if="r.email"> – {{ r.email }}</template></span>
                    </span>
                    <span v-if="r.left" class="badge badge-neutral shrink-0">Uscito</span>
                    <span v-else-if="r.active" class="badge badge-ok shrink-0">In regola</span>
                    <span v-else class="badge badge-warn shrink-0">Da rinnovare</span>
                    <ChevronRight class="hidden size-5 shrink-0 text-muted sm:block" aria-hidden="true" />
                </Link>
            </li>
        </ul>
        <div v-else class="card">
            <EmptyState :title="q || type || status ? 'Nessuna scheda trovata' : 'Ancora nessuna scheda'" :text="q || type || status ? 'Prova con un altro nome o togli il filtro.' : 'Crea la prima scheda o importa l\'elenco da Excel.'">
                <template #icon><UserRound class="size-6" /></template>
                <Link v-if="canEdit && !q && !type" href="/gestionale/soci/nuovo" class="btn btn-primary">Nuova scheda</Link>
            </EmptyState>
        </div>

        <nav v-if="prev || next" class="mt-4 flex justify-between gap-3" aria-label="Pagine">
            <Link v-if="prev" :href="prev" class="btn btn-secondary">Precedenti</Link><span v-else />
            <Link v-if="next" :href="next" class="btn btn-secondary">Successivi</Link>
        </nav>

        <Sheet v-model="tools" title="Altri strumenti">
            <ul class="list-group">
                <li><Link href="/gestionale/certificati" class="list-row" @click="tools = false"><FileText class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Certificati medici</span><ChevronRight class="size-5 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/gestionale/soci/libro" class="list-row" @click="tools = false"><BookOpen class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Libro soci</span><ChevronRight class="size-5 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/gestionale/soci/runts" class="list-row" @click="tools = false"><ListChecks class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Dati per il RUNTS</span><ChevronRight class="size-5 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/gestionale/soci/livelli" class="list-row" @click="tools = false"><ListChecks class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Livelli e quote</span><ChevronRight class="size-5 text-muted" aria-hidden="true" /></Link></li>
                <li v-if="canEdit"><Link href="/gestionale/soci/importa" class="list-row" @click="tools = false"><Upload class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Importa da Excel o CSV</span><ChevronRight class="size-5 text-muted" aria-hidden="true" /></Link></li>
                <li v-if="canExport"><a :href="exportUrl()" class="list-row" @click="tools = false"><Download class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Esporta l'elenco (CSV)</span></a></li>
            </ul>
        </Sheet>
    </AppLayout>
</template>
