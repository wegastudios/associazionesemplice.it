<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import GuestLayout from '../../layouts/GuestLayout.vue';

defineProps({ orgs: { type: Array, required: true } });

const admin = usePage().props.auth?.user?.platform_admin === true;
const choose = (id) => router.post(`/associazioni/${id}`);
</script>

<template>
    <Head title="Scegli l'associazione" />
    <GuestLayout title="Scegli l'associazione">
        <ul v-if="orgs.length" class="space-y-2">
            <li v-for="org in orgs" :key="org.id">
                <button
                    type="button"
                    class="flex w-full items-center justify-between rounded-lg border border-line px-4 py-3 text-left hover:border-accent focus-visible:outline-2 focus-visible:outline-accent"
                    @click="choose(org.id)"
                >
                    <span class="font-semibold">{{ org.name }}</span>
                    <span class="text-sm text-muted">{{ org.role }}</span>
                </button>
            </li>
        </ul>
        <div v-else class="space-y-3">
            <p>Il tuo account non è collegato a nessuna associazione attiva.</p>
            <p class="text-sm text-muted">
                Chiedi a chi gestisce la tua associazione di invitarti, oppure controlla lo stato dell'abbonamento.
            </p>
        </div>
        <p v-if="admin" class="mt-6 text-center text-sm"><Link href="/admin/piattaforma" class="font-semibold text-accent hover:underline">Amministrazione della piattaforma</Link></p>
        <p class="mt-6 text-center text-sm">
            <Link href="/esci" method="post" as="button" class="font-semibold text-accent hover:underline">Esci</Link>
        </p>
    </GuestLayout>
</template>
