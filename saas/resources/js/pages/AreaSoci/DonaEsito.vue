<script setup>
import { Head } from '@inertiajs/vue3';

defineProps({ org: Object, payment: Object });
</script>

<template>
    <Head :title="`Donazione · ${org.name}`" />
    <main class="mx-auto max-w-md px-4 py-10">
        <section class="card p-5 text-center sm:p-6" aria-labelledby="t">
            <h1 id="t" class="text-xl font-bold">
                <template v-if="payment.status === 'settled' || payment.status === 'review'">Donazione ricevuta, grazie!</template>
                <template v-else-if="payment.status === 'pending'">Donazione in attesa di conferma</template>
                <template v-else>Donazione non riuscita</template>
            </h1>
            <p class="mt-1 text-sm text-muted">{{ org.name }}</p>
            <p class="mt-2 text-2xl font-bold tabular-nums">{{ payment.amount }}</p>
            <p v-if="payment.status === 'settled' && payment.receipts.length" class="mt-3 text-sm">Ti abbiamo scritto un'email con la ricevuta n. {{ payment.receipts.join(', ') }}.</p>
            <p v-else-if="payment.status === 'review'" class="mt-3 text-sm">La segreteria sta controllando il pagamento e ti scrive per la ricevuta.</p>
            <p v-else-if="payment.status === 'pending'" class="mt-3 text-sm">Il servizio di pagamento non ha ancora confermato: ricarica la pagina tra qualche istante.</p>
            <p v-else class="mt-3 text-sm text-muted">Non ti è stato addebitato nulla. Puoi riprovare.</p>
            <a :href="`/dona/${org.slug}`" class="btn btn-secondary mt-5 inline-block text-sm">Fai un'altra donazione</a>
        </section>
    </main>
</template>
