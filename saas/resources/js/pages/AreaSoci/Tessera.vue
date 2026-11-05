<script setup>
import { Head } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';

defineProps({ card: Object, qr: String, member: Object });
</script>

<template>
    <Head title="Tessera" />
    <MemberLayout>
        <section class="mx-auto max-w-sm rounded-2xl border border-line bg-card p-6 text-center shadow-sm" aria-labelledby="name">
            <p class="text-sm text-muted">{{ member.org }}</p>
            <h1 id="name" class="mt-1 text-2xl font-bold">{{ card.first_name }} {{ card.last_name }}</h1>
            <p class="text-sm text-muted">{{ card.type_label }}<template v-if="card.card_number"> · tessera n. {{ card.card_number }}</template></p>
            <template v-if="card.member">
                <p class="mt-4 inline-block rounded-full px-3 py-1 text-sm font-semibold" :class="card.active && !card.suspended ? 'bg-salvia/15' : 'bg-danger/15 text-danger'">
                    <template v-if="card.suspended">Sospesa</template>
                    <template v-else-if="card.active">In regola {{ card.year }}<template v-if="card.valid_to"> · fino al {{ card.valid_to }}</template></template>
                    <template v-else>Da rinnovare per il {{ card.year }}</template>
                </p>
                <!-- SVG generato dal server con QrCode::svg (nessun testo dell'utente dentro, l'etichetta è fissa). -->
                <div v-if="qr" class="mx-auto mt-5 w-56" v-html="qr" />
                <p class="mt-2 text-xs text-muted">Mostra il codice all'ingresso: chi lo legge vede se la tessera è valida.</p>
            </template>
            <p v-else class="mt-4 text-sm text-muted">Non è una tessera da socio: qui trovi eventi, quote e ricevute.</p>
            <p v-if="card.left" class="mt-3 text-sm text-danger">Risulti uscito dall'associazione.</p>
        </section>
    </MemberLayout>
</template>
