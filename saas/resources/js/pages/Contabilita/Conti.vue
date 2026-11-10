<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ accounts: Array, types: Array, canEdit: Boolean });

// Commissione di incasso per conto: quota fissa in euro e percentuale, trattenute a ogni incasso di segreteria o sul posto.
const fees = reactive({});
const fee = (a) => (fees[a.id] ??= { fee_fixed: a.fee_fixed, fee_percent: a.fee_percent });
const saveFee = (a) => router.post(`/gestionale/conti/${a.id}/commissione`, fee(a), { preserveScroll: true });

const form = useForm({ name: '', type: 'bank', opening: '' });
const submit = () => form.post('/gestionale/conti', { preserveScroll: true, onSuccess: () => form.reset() });
const toggle = (a) => router.post(`/gestionale/conti/${a.id}/${a.open ? 'chiudi' : 'riapri'}`, {}, { preserveScroll: true });
const field = 'field';
</script>

<template>
    <Head title="Conti" />
    <AppLayout>
        <h1 class="mb-6 text-2xl font-bold">Conti</h1>
        <div class="mb-8 card overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr><th class="px-4 py-3">Conto</th><th class="px-4 py-3">Tipo</th><th class="px-4 py-3 text-right">Saldo iniziale</th><th class="px-4 py-3 text-right">Saldo</th><th class="px-4 py-3">Commissione di incasso</th><th class="px-4 py-3"><span class="sr-only">Azioni</span></th></tr>
                </thead>
                <tbody>
                    <tr v-for="a in accounts" :key="a.id" class="border-t border-line" :class="a.open ? '' : 'text-muted'">
                        <td class="px-4 py-3 font-semibold">{{ a.name }}<span v-if="!a.open" class="ml-2 text-xs">(chiuso)</span></td>
                        <td class="px-4 py-3">{{ a.type }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ a.opening }}</td>
                        <td class="px-4 py-3 text-right font-semibold tabular-nums">{{ a.balance }}</td>
                        <td class="px-4 py-3">
                            <form v-if="canEdit" class="flex flex-wrap items-center gap-1" @submit.prevent="saveFee(a)">
                                <input v-model="fee(a).fee_fixed" inputmode="decimal" :class="field" class="w-20" aria-label="Quota fissa in euro" placeholder="0,00" /> € +
                                <input v-model="fee(a).fee_percent" inputmode="decimal" :class="field" class="w-16" aria-label="Percentuale" placeholder="0" /> %
                                <button type="submit" class="ml-1 font-semibold text-accent hover:underline">Salva</button>
                            </form>
                            <span v-else>{{ a.fee_fixed }} € + {{ a.fee_percent }}%</span>
                        </td>
                        <td class="px-4 py-3 text-right"><button v-if="canEdit" type="button" class="font-semibold text-accent hover:underline" @click="toggle(a)">{{ a.open ? 'Chiudi' : 'Riapri' }}</button></td>
                    </tr>
                </tbody>
            </table>
        </div>
        <form v-if="canEdit" class="space-y-4 card p-4 sm:p-5" @submit.prevent="submit">
            <h2 class="text-lg font-bold">Nuovo conto</h2>
            <div class="grid gap-3 sm:grid-cols-3">
                <label class="space-y-1 text-sm"><span class="font-semibold">Nome</span><input v-model="form.name" required :class="field" /></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Tipo</span>
                    <select v-model="form.type" :class="field"><option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option></select></label>
                <label class="space-y-1 text-sm"><span class="font-semibold">Saldo iniziale</span><input v-model="form.opening" inputmode="decimal" placeholder="0,00" :class="field" /></label>
            </div>
            <p v-if="form.errors.name || form.errors.opening" class="text-sm text-danger">{{ form.errors.name || form.errors.opening }}</p>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Crea il conto</button>
        </form>
    </AppLayout>
</template>
