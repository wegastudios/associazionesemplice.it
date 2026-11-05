<script setup>
import { Head, router } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';

defineProps({ events: Array, member: Object });
const post = (url) => router.post(url, {}, { preserveScroll: true });
const cancel = (e) => confirm(`Annullare la prenotazione a ${e.activity} di ${e.label}?`) && post(`/area/prenotazioni/${e.booking_id}/annulla`);
</script>

<template>
    <Head title="Eventi" />
    <MemberLayout>
        <h1 class="mb-1 text-2xl font-bold">Eventi</h1>
        <p class="mb-4 text-sm text-muted">Le date dei prossimi due mesi. Il contributo si paga in segreteria o online da «Paga».</p>
        <p v-if="!events.length" class="rounded-2xl border border-line bg-card p-6 text-center text-muted">Nessun evento in programma.</p>
        <ul class="space-y-3">
            <li v-for="e in events" :key="e.session_id" class="rounded-2xl border border-line bg-card p-4">
                <div class="flex flex-wrap items-start gap-3">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold">{{ e.activity }}</p>
                        <p class="text-sm first-letter:uppercase">{{ e.label }}<template v-if="e.start"> · {{ e.start }}<template v-if="e.end">–{{ e.end }}</template></template></p>
                        <p v-if="e.location" class="text-sm text-muted">{{ e.location }}</p>
                        <p class="text-sm text-muted">{{ e.fee_label }}<template v-if="e.capacity !== null"> · {{ e.free === 0 ? 'posti esauriti' : e.free + (e.free === 1 ? ' posto libero' : ' posti liberi') }}</template></p>
                    </div>
                    <div class="flex flex-col items-end gap-2 text-sm">
                        <template v-if="e.booking_id">
                            <span class="rounded-full bg-salvia/15 px-3 py-1 font-semibold">Prenotato</span>
                            <span v-if="e.due > 0" class="text-xs text-muted">da pagare in segreteria o in «Paga»</span>
                            <button v-if="e.cancellable" type="button" class="text-danger hover:underline" @click="cancel(e)">Annulla</button>
                            <span v-else class="text-xs text-muted">per annullare scrivi alla segreteria</span>
                        </template>
                        <span v-else-if="e.waiting" class="rounded-full bg-surface px-3 py-1">In lista d'attesa</span>
                        <button v-else-if="e.free === 0" type="button" class="rounded-lg border border-line px-4 py-2 font-semibold" @click="post(`/area/eventi/${e.session_id}/attesa`)">Lista d'attesa</button>
                        <button v-else type="button" class="rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" @click="post(`/area/eventi/${e.session_id}/prenota`)">Prenota</button>
                    </div>
                </div>
            </li>
        </ul>
    </MemberLayout>
</template>
