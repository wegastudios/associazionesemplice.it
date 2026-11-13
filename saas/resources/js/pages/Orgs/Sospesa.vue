<script setup>
import { Head, Link } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';

defineProps({
    orgs: { type: Array, required: true },
    portalUrl: { type: String, required: true },
});
</script>

<template>
    <Head title="Abbonamento scaduto" />
    <GuestLayout title="Abbonamento scaduto">
        <div class="space-y-3">
            <p v-if="orgs.length === 1">
                L'abbonamento di <strong>{{ orgs[0] }}</strong> è scaduto e l'accesso è sospeso.
            </p>
            <p v-else>L'abbonamento di queste associazioni è scaduto e l'accesso è sospeso:</p>
            <ul v-if="orgs.length > 1" class="list-disc pl-5">
                <li v-for="name in orgs" :key="name">{{ name }}</li>
            </ul>
            <p class="text-sm text-muted">
                I dati non sono stati toccati: restano custoditi fino alla cancellazione automatica. Pagando
                dal portale l'accesso torna subito com'era. Finché l'accesso è sospeso non si può esportare.
            </p>
            <a :href="portalUrl" class="mt-2 inline-flex min-h-11 items-center rounded-lg bg-accent px-5 font-bold text-white">
                Riattiva dal portale
            </a>
        </div>
        <p class="mt-6 text-center text-sm">
            <Link href="/esci" method="post" as="button" class="font-semibold text-accent hover:underline">Esci</Link>
        </p>
    </GuestLayout>
</template>
