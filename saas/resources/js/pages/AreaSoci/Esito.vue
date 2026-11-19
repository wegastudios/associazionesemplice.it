<script setup>
import { Head, Link } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';

defineProps({ member: Object, payment: Object });
</script>

<template>
    <Head title="Esito del pagamento" />
    <MemberLayout>
        <section class="mx-auto max-w-md card p-5 sm:p-6 text-center" aria-labelledby="t">
            <h1 id="t" class="text-xl font-bold">
                <template v-if="payment.status === 'settled'">Pagamento ricevuto, grazie.</template>
                <template v-else-if="payment.status === 'review'">Pagamento ricevuto</template>
                <template v-else-if="payment.status === 'pending'">Pagamento in attesa di conferma</template>
                <template v-else>Pagamento non riuscito</template>
            </h1>
            <p v-if="payment.status === 'failed'" class="mt-2 text-sm text-muted">Non ti è stato addebitato nulla. Puoi riprovare o pagare in segreteria.</p>
            <p class="mt-2 text-2xl font-bold tabular-nums">{{ payment.amount }}</p>
            <ul class="mt-3 text-sm text-muted"><li v-for="i in payment.items" :key="i">{{ i }}</li></ul>
            <p v-if="payment.receipts.length" class="mt-3 text-sm">{{ payment.receipts.length === 1 ? 'Ricevuta n.' : 'Ricevute n.' }} {{ payment.receipts.join(', ') }}: {{ payment.receipts.length === 1 ? 'la trovi' : 'le trovi' }} in «Quote».</p>
            <p v-else-if="payment.status === 'review'" class="mt-3 text-sm">La segreteria lo sta controllando.</p>
            <p v-else-if="payment.status === 'pending'" class="mt-3 text-sm">Il servizio di pagamento non ha ancora confermato: ricarica la pagina tra qualche istante.</p>
            <div class="mt-5 flex justify-center gap-3 text-sm">
                <Link href="/area/quote" class="btn btn-primary">Quote e ricevute</Link>
                <Link href="/area/paga" class="btn btn-secondary">Torna ai pagamenti</Link>
            </div>
        </section>
    </MemberLayout>
</template>
