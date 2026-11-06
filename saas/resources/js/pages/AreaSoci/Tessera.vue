<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';
import { ChevronRight, CreditCard } from '../../components/ui/icons.js';

defineProps({ card: Object, qr: String, member: Object, due: { type: Object, default: null } });
</script>

<template>
    <Head title="Tessera" />
    <MemberLayout>
        <Link v-if="due" href="/area/paga" class="card mb-4 flex items-center gap-3 border-warn-ink/30 bg-warn-soft p-4 text-warn-ink">
            <CreditCard class="size-6 shrink-0" aria-hidden="true" />
            <span class="min-w-0 flex-1"><span class="block font-semibold">{{ due.count === 1 ? 'Hai 1 pagamento da fare' : `Hai ${due.count} pagamenti da fare` }}</span><span class="block text-sm">Totale {{ due.total }}</span></span>
            <ChevronRight class="size-5 shrink-0" aria-hidden="true" />
        </Link>

        <!-- La tessera: sempre su fondo indaco con testo bianco e QR su pannello bianco, leggibile anche con poca luce. -->
        <section class="mx-auto max-w-sm overflow-hidden rounded-3xl bg-indaco p-6 text-white" aria-labelledby="name">
            <div class="flex items-center gap-3">
                <img src="/assets/brand/simbolo-negativo.svg" alt="" class="size-9 shrink-0" />
                <p class="min-w-0 flex-1 truncate text-sm font-semibold text-white/85">{{ member.org }}</p>
            </div>
            <h1 id="name" class="mt-6 text-2xl font-bold leading-tight">{{ card.first_name }} {{ card.last_name }}</h1>
            <p class="mt-1 text-sm text-white/85">{{ card.type_label }}<template v-if="card.card_number">, tessera n. {{ card.card_number }}</template></p>
            <template v-if="card.member">
                <p class="mt-4 inline-flex items-center rounded-full px-3 py-1 text-sm font-bold" :class="card.active && !card.suspended ? 'bg-white text-ok-ink' : 'bg-giallo text-warn-ink'">
                    <template v-if="card.suspended">Tessera sospesa</template>
                    <template v-else-if="card.active">In regola {{ card.year }}<template v-if="card.valid_to">, fino al {{ card.valid_to }}</template></template>
                    <template v-else>Da rinnovare per il {{ card.year }}</template>
                </p>
                <!-- SVG generato dal server con QrCode::svg: nessun testo dell'utente dentro, l'etichetta è fissa. -->
                <div v-if="qr" class="mx-auto mt-5 w-full max-w-60 rounded-2xl bg-white p-3" v-html="qr" />
                <p class="mt-3 text-center text-[13px] text-white/80">Mostra il codice all'ingresso: chi lo legge vede se la tessera è valida.</p>
            </template>
            <p v-else class="mt-4 text-sm text-white/85">Non è una tessera da socio: qui trovi eventi, quote e ricevute.</p>
            <p v-if="card.left" class="mt-3 rounded-lg bg-white/15 px-3 py-2 text-sm font-semibold">Risulti uscito dall'associazione.</p>
        </section>
    </MemberLayout>
</template>
