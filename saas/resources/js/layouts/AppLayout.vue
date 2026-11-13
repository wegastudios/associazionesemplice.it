<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import FlashMessages from '../components/ui/FlashMessages.vue';
import Avatar from '../components/ui/Avatar.vue';
import Sheet from '../components/ui/Sheet.vue';
import {
    Banknote, BookOpen, CalendarDays, ChartColumn, ChevronRight, CreditCard, Download, Ellipsis, FileText, House, Landmark, LogOut,
    MapPin, Plus, Receipt, Settings, ShieldCheck, UserPlus, UserRound, Users, Wallet,
} from '../components/ui/icons.js';

const page = usePage();
const org = computed(() => page.props.org);
const user = computed(() => page.props.auth?.user);
const can = (a) => !a || (org.value?.can ?? []).includes(a);
const has = (p) => !p || (org.value?.packages ?? []).includes(p);
const allowed = (i) => can(i.can) && has(i.pkg);

// Tutte le sezioni, raggruppate come nel foglio «Altro» e nella barra laterale (docs/DESIGN.md §5).
const groups = [
    { label: 'Persone', items: [
        { href: '/gestionale/soci', label: 'Soci', icon: Users, can: 'people.view' },
        { href: '/gestionale/domande', label: 'Domande di adesione', short: 'Domande', icon: UserPlus, can: 'people.view' },
    ] },
    { label: 'Attività e risorse', items: [
        { href: '/gestionale/attivita', label: 'Attività', icon: BookOpen, can: 'people.view' },
        { href: '/gestionale/calendario', label: 'Calendario', icon: CalendarDays, can: 'people.view' },
        { href: '/gestionale/risorse', label: 'Risorse', icon: MapPin, can: 'people.view', pkg: 'resources' },
    ] },
    { label: 'Cassa e conti', items: [
        { href: '/gestionale/prima-nota', label: 'Prima nota', icon: Wallet, can: 'accounting.view' },
        { href: '/gestionale/conti', label: 'Conti', icon: Landmark, can: 'accounting.view' },
        { href: '/gestionale/rendiconto', label: 'Rendiconto', icon: ChartColumn, can: 'accounting.view' },
        { href: '/gestionale/pagamenti-online', label: 'Pagamenti online', icon: CreditCard, can: 'accounting.view', pkg: 'member_area' },
    ] },
    { label: 'Gestione', items: [
        { href: '/gestionale/abbonamento', label: 'Abbonamento e pacchetti', icon: CreditCard, can: 'org.subscription', external: true },
        { href: '/gestionale/operatori', label: 'Operatori e ruoli', icon: ShieldCheck, can: 'org.operators' },
        { href: '/gestionale/esportazione', label: 'Esportazione dei dati', icon: Download, can: 'org.settings' },
        { href: '/gestionale/configurazione', label: 'Configurazione guidata', icon: Settings, can: 'org.settings' },
    ] },
];
const visibleGroups = computed(() => groups.map((g) => ({ ...g, items: g.items.filter(allowed) })).filter((g) => g.items.length));
const flat = computed(() => visibleGroups.value.flatMap((g) => g.items));

// Cosa si può creare, in base ai permessi.
const creates = computed(() => [
    { href: '/gestionale/soci/nuovo', label: 'Nuova scheda socio', hint: 'Anagrafica e tessera', icon: UserPlus, can: 'people.edit' },
    { href: '/gestionale/prima-nota?nuovo=incasso', label: 'Registra un incasso', hint: 'Quote, contributi, donazioni', icon: Banknote, can: 'accounting.edit' },
    { href: '/gestionale/prima-nota?nuovo=spesa', label: 'Registra una spesa', hint: 'Affitto, materiali, utenze', icon: Receipt, can: 'accounting.edit' },
    { href: '/gestionale/attivita/nuova', label: 'Nuova attività', hint: 'Corso o evento', icon: BookOpen, can: 'people.edit' },
    { href: '/gestionale/risorse/nuova', label: 'Nuova risorsa', hint: 'Campo, sala, posto barca', icon: MapPin, can: 'people.edit', pkg: 'resources' },
].filter(allowed));

// Barra in basso: Home, fino a tre sezioni scelte per permessi e pacchetti, poi «Altro».
const tabs = computed(() => {
    const byHref = Object.fromEntries(flat.value.map((i) => [i.href, i]));
    const order = has('resources')
        ? ['/gestionale/soci', '/gestionale/risorse', '/gestionale/prima-nota', '/gestionale/attivita']
        : ['/gestionale/soci', '/gestionale/attivita', '/gestionale/prima-nota', '/gestionale/risorse'];
    return [{ href: '/gestionale', label: 'Home', icon: House }, ...order.map((h) => byHref[h]).filter(Boolean).slice(0, 3).map((i) => ({ ...i, label: i.short ?? i.label }))];
});

const path = computed(() => page.url.split('?')[0]);
const current = (href) => (href === '/gestionale' ? path.value === href : path.value === href || path.value.startsWith(`${href}/`));
const moreActive = computed(() => flat.value.some((i) => current(i.href)) && !tabs.value.some((t) => current(t.href)));
const showNew = ref(false);
const showMore = ref(false);
const showAccount = ref(false);
</script>

<template>
    <div class="min-h-full pt-14 lg:pl-64">
        <!-- Barra comune a portale, supporto e gestionale (docs/GRAFICA_UX.md §2): logo, nome del posto, Il mio account. -->
        <div class="print:hidden fixed inset-x-0 top-0 z-40 h-14 border-b border-line bg-card">
            <div class="mx-auto flex h-14 max-w-7xl items-center gap-3 px-4">
                <a href="https://associazionesemplice.it/" class="flex shrink-0 items-center" aria-label="Associazione Semplice, vai alla home">
                    <picture>
                        <source srcset="/assets/brand/logo-orizzontale-negativo.svg" media="(prefers-color-scheme: dark)" />
                        <img src="/assets/brand/logo-orizzontale-colore.svg" alt="" class="hidden h-8 w-auto sm:block" />
                    </picture>
                    <img src="/assets/brand/simbolo-colore.svg" alt="" class="size-8 sm:hidden" />
                </a>
                <span class="min-w-0 flex-1 truncate border-l border-line pl-3 text-[15px] font-bold">Gestionale</span>
                <a href="https://associazionesemplice.it/account" class="inline-flex min-h-11 shrink-0 items-center rounded-xl px-2 text-[15px] font-semibold text-accent hover:bg-surface">Il mio account</a>
            </div>
        </div>
        <!-- Barra laterale: da 1024 px -->
        <aside class="print:hidden fixed bottom-0 left-0 top-14 z-30 hidden w-64 flex-col border-r border-line bg-card lg:flex" aria-label="Menu del gestionale">
            <div v-if="org" class="px-5 pb-3 pt-5">
                <p class="truncate font-bold">{{ org.name }}</p>
                <p class="caption">{{ org.role }}</p>
            </div>
            <div v-if="creates.length" class="px-4 pb-3">
                <button type="button" class="btn btn-primary btn-block" @click="showNew = true"><Plus class="size-5" aria-hidden="true" />Nuovo</button>
            </div>
            <nav class="flex-1 overflow-y-auto px-3 pb-4" aria-label="Sezioni">
                <Link href="/gestionale" class="mb-1 flex min-h-11 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold hover:bg-surface"
                    :class="current('/gestionale') ? 'bg-info-soft text-info-ink' : 'text-ink'" :aria-current="current('/gestionale') ? 'page' : undefined">
                    <House class="size-5" aria-hidden="true" />Home
                </Link>
                <div v-for="g in visibleGroups" :key="g.label" class="mt-3">
                    <p class="px-3 pb-1 text-[13px] font-semibold text-muted">{{ g.label }}</p>
                    <component :is="i.external ? 'a' : Link" v-for="i in g.items" :key="i.href" :href="i.href" class="flex min-h-10 items-center gap-3 rounded-xl px-3 text-[15px] font-semibold hover:bg-surface"
                        :class="current(i.href) ? 'bg-info-soft text-info-ink' : 'text-ink'" :aria-current="current(i.href) ? 'page' : undefined">
                        <component :is="i.icon" class="size-5" aria-hidden="true" />{{ i.label }}
                    </component>
                </div>
            </nav>
            <div class="border-t border-line p-3">
                <button v-if="user" type="button" class="flex min-h-12 w-full items-center gap-3 rounded-xl px-2 text-left hover:bg-surface" @click="showAccount = true">
                    <Avatar :name="user.name" size="sm" />
                    <span class="min-w-0 flex-1"><span class="block truncate text-sm font-semibold">{{ user.name }}</span><span class="caption block">Account</span></span>
                    <ChevronRight class="size-4 shrink-0 text-muted" aria-hidden="true" />
                </button>
            </div>
        </aside>

        <!-- Intestazione bassa: telefono e tablet -->
        <header class="print:hidden sticky top-14 z-30 border-b border-line bg-card/95 backdrop-blur lg:hidden">
            <div class="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
                <div v-if="org" class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-bold leading-5">{{ org.name }}</p>
                    <p class="caption truncate">{{ org.role }}</p>
                </div>
                <button v-if="creates.length" type="button" class="btn btn-primary btn-sm shrink-0" @click="showNew = true"><Plus class="size-4" aria-hidden="true" />Nuovo</button>
            </div>
        </header>

        <main class="mx-auto max-w-5xl px-4 pb-28 pt-5 lg:px-8 lg:pb-12 lg:pt-8">
            <p v-if="user" class="sr-only">Collegato come {{ user.name }}</p>
            <p v-if="org?.read_only" role="status" class="mb-4 rounded-lg bg-giallo px-4 py-3 text-sm font-semibold text-warn-ink">
                La prova è finita: puoi consultare i dati ma non modificarli né esportarli. Scegli un piano dal portale per tornare a lavorare.
            </p>
            <FlashMessages />
            <slot />
        </main>

        <!-- Barra in basso: telefono e tablet -->
        <nav class="print:hidden fixed inset-x-0 bottom-0 z-30 border-t border-line bg-card pb-[env(safe-area-inset-bottom)] shadow-bar lg:hidden" aria-label="Sezioni">
            <ul class="mx-auto grid max-w-3xl auto-cols-fr grid-flow-col">
                <li v-for="t in tabs" :key="t.href">
                    <Link :href="t.href" class="flex min-h-[3.75rem] flex-col items-center justify-center gap-0.5 px-1 text-[12px] font-semibold" :class="current(t.href) ? 'text-accent' : 'text-muted'"
                        :aria-current="current(t.href) ? 'page' : undefined">
                        <component :is="t.icon" class="size-6" :stroke-width="current(t.href) ? 2.25 : 1.75" aria-hidden="true" />{{ t.label }}
                    </Link>
                </li>
                <li>
                    <button type="button" class="flex min-h-[3.75rem] w-full flex-col items-center justify-center gap-0.5 px-1 text-[12px] font-semibold" :class="moreActive ? 'text-accent' : 'text-muted'" @click="showMore = true">
                        <Ellipsis class="size-6" aria-hidden="true" />Altro
                    </button>
                </li>
            </ul>
        </nav>

        <Sheet v-model="showNew" title="Cosa vuoi fare?">
            <ul class="list-group -mx-0">
                <li v-for="c in creates" :key="c.href">
                    <Link :href="c.href" class="list-row" @click="showNew = false">
                        <span class="grid size-10 shrink-0 place-items-center rounded-full bg-info-soft text-info-ink"><component :is="c.icon" class="size-5" aria-hidden="true" /></span>
                        <span class="min-w-0 flex-1"><span class="block font-semibold">{{ c.label }}</span><span class="caption block">{{ c.hint }}</span></span>
                        <ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" />
                    </Link>
                </li>
            </ul>
        </Sheet>

        <Sheet v-model="showAccount" title="Il tuo account" :description="user?.email">
            <ul class="list-group">
                <li><Link href="/gestionale/profilo" class="list-row" @click="showAccount = false"><UserRound class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Il tuo profilo</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/account/verifica-in-due-passaggi" class="list-row" @click="showAccount = false"><ShieldCheck class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Sicurezza</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/associazioni" class="list-row" @click="showAccount = false"><Landmark class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Cambia associazione</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/esci" method="post" as="button" class="list-row" @click="showAccount = false"><LogOut class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Esci</span></Link></li>
            </ul>
        </Sheet>

        <Sheet v-model="showMore" title="Tutte le sezioni">
            <div v-for="g in visibleGroups" :key="g.label" class="mb-4">
                <p class="caption mb-1.5 px-1 font-semibold">{{ g.label }}</p>
                <ul class="list-group">
                    <li v-for="i in g.items" :key="i.href">
                        <component :is="i.external ? 'a' : Link" :href="i.href" class="list-row" :class="current(i.href) ? 'text-accent' : ''" @click="showMore = false">
                            <component :is="i.icon" class="size-5 shrink-0 text-muted" aria-hidden="true" />
                            <span class="flex-1 font-semibold">{{ i.label }}</span>
                            <ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" />
                        </component>
                    </li>
                </ul>
            </div>
            <p class="caption mb-1.5 px-1 font-semibold">Il tuo account</p>
            <ul class="list-group">
                <li><Link href="/gestionale/profilo" class="list-row" @click="showMore = false"><UserRound class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Il tuo profilo</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/account/verifica-in-due-passaggi" class="list-row" @click="showMore = false"><ShieldCheck class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Sicurezza</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/associazioni" class="list-row" @click="showMore = false"><Landmark class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Cambia associazione</span><ChevronRight class="size-5 shrink-0 text-muted" aria-hidden="true" /></Link></li>
                <li><Link href="/esci" method="post" as="button" class="list-row" @click="showMore = false"><LogOut class="size-5 shrink-0 text-muted" aria-hidden="true" /><span class="flex-1 font-semibold">Esci</span></Link></li>
            </ul>
            <p v-if="user" class="caption mt-3 px-1">Collegato come {{ user.name }}</p>
        </Sheet>
    </div>
</template>
