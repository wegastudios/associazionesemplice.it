<script setup>
import { Head, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ years: Array, statement: Object, canClose: Boolean });

const reopen = useForm({ reason: '', confirm_year: '' });
const close = () => window.confirm(`Chiudere l'esercizio ${props.statement.year}? Non potrai più registrare né stornare movimenti con date di quell'anno finché non lo riapri.`) && router.post(`/gestionale/esercizi/${props.statement.year}/chiudi`, {}, { preserveScroll: true });
const submitReopen = () => reopen.post(`/gestionale/esercizi/${props.statement.year}/riapri`, { preserveScroll: true, onSuccess: () => reopen.reset() });
const print = () => window.print();
const go = (year) => router.get(`/gestionale/rendiconto/${year}`);
const field = 'field';
</script>

<template>
    <Head :title="`Rendiconto ${statement.year}`" />
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Rendiconto per cassa {{ statement.year }}</h1>
                <p class="text-sm text-muted">Entrate e uscite dell’anno per categoria, nello schema del Mod. D degli enti del Terzo settore: il documento da portare in assemblea e al commercialista.</p>
                <p class="text-sm text-muted">Dal {{ statement.starts_on }} al {{ statement.ends_on }} · {{ statement.status === 'closed' ? 'esercizio chiuso' : 'esercizio aperto' }}</p>
            </div>
            <div class="flex items-end gap-3 print:hidden">
            <button type="button" class="btn btn-secondary" @click="print">Stampa o salva in PDF</button>
            <label class="space-y-1 text-sm">
                <span class="block font-semibold">Esercizio</span>
                <select :class="field" :value="statement.year" @change="go($event.target.value)">
                    <option v-for="y in years" :key="y.year" :value="y.year">{{ y.year }}</option>
                </select>
            </label>
            </div>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <section v-for="side in [['Entrate', statement.income, statement.totals.income], ['Uscite', statement.expense, statement.totals.expense]]" :key="side[0]" class="card p-4 sm:p-5">
                <h2 class="mb-3 text-lg font-bold">{{ side[0] }}</h2>
                <p v-if="!side[1].length" class="text-sm text-muted">Nessun movimento.</p>
                <table v-else class="w-full text-sm">
                    <tbody>
                        <tr v-for="l in side[1]" :key="l.group + l.category" class="border-t border-line">
                            <td class="py-2"><span class="block text-xs text-muted">{{ l.group }}</span>{{ l.category }}</td>
                            <td class="py-2 text-right tabular-nums">{{ l.amount }}</td>
                        </tr>
                    </tbody>
                </table>
                <p class="mt-3 flex justify-between border-t border-line pt-3 font-bold"><span>Totale</span><span class="tabular-nums">{{ side[2] }}</span></p>
            </section>
        </div>

        <p class="mt-6 card p-4 sm:p-5 text-lg font-bold">Avanzo (o disavanzo) dell’esercizio: <span class="tabular-nums">{{ statement.totals.result }}</span></p>

        <section class="mt-6 card p-4 sm:p-5">
            <h2 class="mb-3 text-lg font-bold">Conti</h2>
            <table class="w-full text-sm">
                <thead class="text-left text-muted"><tr><th class="py-2">Conto</th><th class="py-2 text-right">Inizio esercizio</th><th class="py-2 text-right">Fine esercizio</th></tr></thead>
                <tbody>
                    <tr v-for="a in statement.accounts" :key="a.name" class="border-t border-line">
                        <td class="py-2">{{ a.name }}</td><td class="py-2 text-right tabular-nums">{{ a.opening }}</td><td class="py-2 text-right tabular-nums">{{ a.closing }}</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section v-if="canClose" class="mt-6 card p-4 sm:p-5 print:hidden">
            <h2 class="mb-3 text-lg font-bold">Chiusura dell'esercizio</h2>
            <template v-if="statement.status === 'open'">
                <p class="mb-3 text-sm text-muted">Chiudi l’esercizio dopo l’approvazione del rendiconto. Da quel momento non si registrano né si stornano movimenti con date di quell’anno; se serve, si può riaprire.</p>
                <button type="button" class="btn btn-secondary" @click="close">Chiudi l'esercizio {{ statement.year }}</button>
            </template>
            <form v-else class="flex flex-wrap items-end gap-3" @submit.prevent="submitReopen">
                <label class="min-w-64 flex-1 space-y-1 text-sm"><span class="font-semibold">Motivo della riapertura</span><input v-model="reopen.reason" required :class="field" /></label>
                <label class="w-48 space-y-1 text-sm"><span class="font-semibold">Scrivi {{ statement.year }} per confermare</span><input v-model="reopen.confirm_year" required inputmode="numeric" autocomplete="off" :class="field" /></label>
                <button type="submit" class="btn btn-secondary">Riapri l'esercizio</button>
            </form>
        </section>
    </AppLayout>
</template>
