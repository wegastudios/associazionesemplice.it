<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import BrandLogo from '../components/BrandLogo.vue';

const page = usePage();
const user = computed(() => page.props.auth.user);
const org = computed(() => page.props.org);
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
        </header>
        <main class="mx-auto max-w-6xl px-4 py-8">
            <p v-if="user" class="sr-only">Collegato come {{ user.name }}</p>
            <slot />
        </main>
    </div>
</template>
