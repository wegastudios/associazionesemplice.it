<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ application: Object, firstFreeCard: String, canEdit: Boolean });
const d = props.application.data;
const g = d.guardian ?? {};
const approveForm = useForm({ card_number: props.firstFreeCard });
const rejectForm = useForm({ reason: '' });
const approve = () => approveForm.post(`/gestionale/domande/${props.application.id}/accogli`);
const reject = () => rejectForm.post(`/gestionale/domande/${props.application.id}/respingi`);
const date = (iso) => (iso ? iso.split('-').reverse().join('/') : '');
const yes = (v) => (v ? 'sì' : 'no');
const field = 'field';
</script>

<template>
    <Head title="Domanda di adesione" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/domande" class="text-accent hover:underline">← Domande di adesione</Link></p>
        <h1 class="mb-1 text-2xl font-bold">{{ d.last_name }} {{ d.first_name }}</h1>
        <p class="mb-4 text-sm text-muted">Arrivata il {{ application.submitted }} · {{ application.type }}<template v-if="application.minor"> · minore</template></p>

        <section class="card p-4 sm:p-5">
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">Data di nascita</dt><dd>{{ date(d.birth_date) }}<template v-if="d.birth_place"> · {{ d.birth_place }}</template></dd></div>
                <div><dt class="text-muted">Codice fiscale</dt><dd>{{ d.tax_code }}</dd></div>
                <div><dt class="text-muted">Email</dt><dd>{{ d.email || '—' }}</dd></div>
                <div><dt class="text-muted">Cellulare</dt><dd>{{ d.phone || '—' }}</dd></div>
                <div class="sm:col-span-2"><dt class="text-muted">Indirizzo</dt><dd>{{ d.address || '—' }}</dd></div>
                <div><dt class="text-muted">Regolamento accettato</dt><dd>{{ yes(d.rules_ok) }}</dd></div>
                <div><dt class="text-muted">Uso delle immagini</dt><dd>{{ yes(d.images_ok) }}</dd></div>
            </dl>
            <template v-if="application.minor">
                <h2 class="mb-2 mt-5 font-bold">Genitore o tutore</h2>
                <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                    <div><dt class="text-muted">Nome</dt><dd>{{ g.first_name }} {{ g.last_name }}</dd></div>
                    <div><dt class="text-muted">Codice fiscale</dt><dd>{{ g.tax_code || '—' }}</dd></div>
                    <div><dt class="text-muted">Email</dt><dd>{{ g.email }}</dd></div>
                    <div><dt class="text-muted">Cellulare</dt><dd>{{ g.phone }}</dd></div>
                </dl>
                <p class="mt-2 text-sm text-muted">Accogliendo la domanda il genitore viene collegato al minore: se ha già una scheda (stesso codice fiscale o email) si usa quella.</p>
            </template>
        </section>

        <div v-if="application.status === 'pending' && canEdit" class="mt-6 grid gap-6 sm:grid-cols-2">
            <form class="space-y-3 card p-4 sm:p-5 text-sm" @submit.prevent="approve">
                <h2 class="text-lg font-bold">Accogli</h2>
                <label class="block space-y-1"><span class="font-semibold">Numero di tessera</span><input v-model="approveForm.card_number" :class="field" maxlength="40" /></label>
                <button type="submit" class="btn btn-primary" :disabled="approveForm.processing">Accogli e crea la scheda</button>
            </form>
            <form class="space-y-3 card p-4 sm:p-5 text-sm" @submit.prevent="reject">
                <h2 class="text-lg font-bold">Respingi</h2>
                <label class="block space-y-1"><span class="font-semibold">Motivo</span><input v-model="rejectForm.reason" :class="field" maxlength="200" required /></label>
                <p v-if="rejectForm.errors.reason" class="text-danger">{{ rejectForm.errors.reason }}</p>
                <button type="submit" class="btn btn-danger" :disabled="rejectForm.processing || !rejectForm.reason.trim()">Respingi</button>
            </form>
        </div>
        <p v-else-if="application.status !== 'pending'" class="mt-6 text-sm">Domanda {{ application.status === 'approved' ? 'accolta' : 'respinta' }}<template v-if="application.reason">: {{ application.reason }}</template>.
            <Link v-if="application.person_id" :href="`/gestionale/soci/${application.person_id}`" class="font-semibold text-accent hover:underline">Apri la scheda</Link>
        </p>
    </AppLayout>
</template>
