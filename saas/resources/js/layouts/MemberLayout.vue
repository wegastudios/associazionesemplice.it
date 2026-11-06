<script setup>
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FlashMessages from '../components/ui/FlashMessages.vue';
import { CalendarDays, IdCard, MapPin, Receipt, UserRound } from '../components/ui/icons.js';

const page = usePage();
const member = computed(() => page.props.member);
// Quote, ricevute e pagamenti stanno nella stessa sezione.
const sections = computed(() => [
    { href: '/area', label: 'Tessera', icon: IdCard, match: ['/area'], exact: true },
    { href: '/area/eventi', label: 'Eventi', icon: CalendarDays, match: ['/area/eventi'] },
    ...(member.value?.resources ? [{ href: '/area/risorse', label: 'Prenota', icon: MapPin, match: ['/area/risorse'] }] : []),
    { href: '/area/quote', label: 'Quote', icon: Receipt, match: ['/area/quote', '/area/paga', '/area/pagamenti'] },
    { href: '/area/profilo', label: 'Profilo', icon: UserRound, match: ['/area/profilo'] },
]);
const path = computed(() => page.url.split('?')[0]);
const current = (s) => (s.exact ? path.value === s.href : s.match.some((m) => path.value === m || path.value.startsWith(`${m}/`)));
const others = computed(() => (member.value?.profiles ?? []).length > 1);
const open = (id) => router.post(`/area/scheda/${id}`);
</script>

<template>
    <div class="min-h-full pb-24 sm:pb-8">
        <header class="sticky top-0 z-30 border-b border-line bg-card/95 pt-[env(safe-area-inset-top)] backdrop-blur">
            <div class="mx-auto flex h-14 max-w-3xl items-center gap-3 px-4">
                <img src="/assets/brand/simbolo-colore.svg" alt="" class="size-8 shrink-0" />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-[15px] font-bold leading-5">{{ member?.org }}</p>
                    <p class="caption">Area soci</p>
                </div>
                <Link href="/area/esci" method="post" as="button" class="btn btn-ghost btn-sm">Esci</Link>
            </div>
            <nav class="mx-auto hidden max-w-3xl gap-1 px-4 pb-2 text-sm font-semibold sm:flex" aria-label="Area soci">
                <Link v-for="s in sections" :key="s.href" :href="s.href" class="inline-flex min-h-10 items-center gap-2 rounded-xl px-3 hover:bg-surface"
                    :class="current(s) ? 'bg-info-soft text-info-ink' : 'text-muted'" :aria-current="current(s) ? 'page' : undefined"><component :is="s.icon" class="size-5" aria-hidden="true" />{{ s.label }}</Link>
            </nav>
        </header>
        <main class="mx-auto max-w-3xl px-4 py-5">
            <div v-if="others" class="-mx-4 mb-4 flex items-center gap-2 overflow-x-auto px-4 pb-1" role="group" aria-label="Scheda aperta">
                <button v-for="p in member.profiles" :key="p.id" type="button" class="inline-flex min-h-10 shrink-0 items-center rounded-full border px-4 text-sm font-semibold transition-colors"
                    :class="p.id === member.active ? 'border-accent bg-accent text-accent-ink' : 'border-line bg-card'" :aria-pressed="p.id === member.active" @click="open(p.id)">{{ p.own ? 'Io' : p.name }}</button>
            </div>
            <FlashMessages />
            <slot />
        </main>
        <nav class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-card pb-[env(safe-area-inset-bottom)] shadow-bar sm:hidden" aria-label="Area soci">
            <ul class="grid auto-cols-fr grid-flow-col">
                <li v-for="s in sections" :key="s.href">
                    <Link :href="s.href" class="flex min-h-[3.75rem] flex-col items-center justify-center gap-0.5 px-1 text-[12px] font-semibold" :class="current(s) ? 'text-accent' : 'text-muted'"
                        :aria-current="current(s) ? 'page' : undefined"><component :is="s.icon" class="size-6" :stroke-width="current(s) ? 2.25 : 1.75" aria-hidden="true" />{{ s.label }}</Link>
                </li>
            </ul>
        </nav>
    </div>
</template>
