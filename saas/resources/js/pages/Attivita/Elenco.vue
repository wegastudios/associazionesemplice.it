<script setup>
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

defineProps({ year: String, years: Array, rows: Array, totals: Object, canEdit: Boolean });
const field = 'field';
</script>

<template>
    <Head title="Attività" />
    <AppLayout>
        <div class="mb-6 flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-2xl font-bold">Attività</h1>
                <p class="text-sm text-muted">Corsi ed eventi: ognuno raccoglie i suoi incassi e le sue spese.</p>
            </div>
            <div class="flex flex-wrap items-end gap-3 text-sm">
                <label class="space-y-1"><span class="block font-semibold">Anno sociale</span>
                    <select :class="field" :value="year" @change="router.get('/gestionale/attivita', { anno: $event.target.value })"><option v-for="y in years" :key="y" :value="y">{{ y }}</option></select>
                </label>
                <Link href="/gestionale/calendario" class="btn btn-secondary">Calendario</Link>
                <Link v-if="canEdit" href="/gestionale/attivita/nuova" class="btn btn-primary">Nuova attività</Link>
            </div>
        </div>

        <div class="sm:hidden">
            <p v-if="!rows.length" class="card p-4 text-center text-sm text-muted">Ancora nessun corso o evento per questo anno sociale. Crea il primo con «Nuova attività».</p>
            <ul v-else class="space-y-3">
                <li v-for="r in rows" :key="r.id" class="card space-y-2 p-4" :class="r.archived ? 'text-muted' : ''">
                    <Link :href="`/gestionale/attivita/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link>
                    <p class="text-xs text-muted">{{ r.kind }}<template v-if="r.archived"> · archiviata</template> · {{ r.participants }} iscritti</p>
                    <p class="flex justify-between text-sm"><span>Entrate</span><span class="tabular-nums">{{ r.income_label }}</span></p>
                    <p class="flex justify-between text-sm"><span>Uscite</span><span class="tabular-nums">{{ r.expense_label }}</span></p>
                    <p class="flex justify-between text-sm font-semibold" :class="r.negative ? 'text-danger' : ''"><span>Risultato</span><span class="tabular-nums">{{ r.balance_label }}</span></p>
                </li>
            </ul>
        </div>

        <div class="hidden card overflow-x-auto sm:block">
            <table class="w-full text-sm">
                <thead class="text-left text-muted">
                    <tr>
                        <th class="px-4 py-3 font-semibold">Attività</th>
                        <th class="px-4 py-3 font-semibold">Iscritti</th>
                        <th class="px-4 py-3 text-right font-semibold">Entrate</th>
                        <th class="px-4 py-3 text-right font-semibold">Uscite</th>
                        <th class="px-4 py-3 text-right font-semibold">Risultato</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="!rows.length"><td colspan="5" class="px-4 py-6 text-center text-muted">Ancora nessun corso o evento per questo anno sociale. Crea il primo con «Nuova attività».</td></tr>
                    <tr v-for="r in rows" :key="r.id" class="border-t border-line" :class="r.archived ? 'text-muted' : ''">
                        <td class="px-4 py-3">
                            <Link :href="`/gestionale/attivita/${r.id}`" class="font-semibold text-accent hover:underline">{{ r.name }}</Link>
                            <span class="ml-2 text-xs text-muted">{{ r.kind }}<template v-if="r.archived"> · archiviata</template></span>
                        </td>
                        <td class="px-4 py-3 tabular-nums">{{ r.participants }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ r.income_label }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right tabular-nums">{{ r.expense_label }}</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right font-semibold tabular-nums" :class="r.negative ? 'text-danger' : ''">{{ r.balance_label }}</td>
                    </tr>
                </tbody>
                <tfoot v-if="rows.length" class="border-t-2 border-line font-semibold">
                    <tr>
                        <td class="px-4 py-3" colspan="2">Totale</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ totals.income }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ totals.expense }}</td>
                        <td class="px-4 py-3 text-right tabular-nums">{{ totals.balance }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </AppLayout>
</template>
