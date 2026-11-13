<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ levels: Array, bases: Array, canEdit: Boolean });

const form = useForm({ name: '', base_type: 'ordinary', fee: '' });
const create = () => form.post('/gestionale/soci/livelli', { preserveScroll: true, onSuccess: () => form.reset() });
const edits = props.levels.map((l) => useForm({ name: l.name, base_type: l.base, fee: l.fee, active: l.active, votes: l.votes }));
const save = (i) => edits[i].put(`/gestionale/soci/livelli/${props.levels[i].id}`, { preserveScroll: true });
const field = 'field';
</script>

<template>
    <Head title="Livelli di socio" />
    <AppLayout>
        <p class="mb-2 text-sm"><Link href="/gestionale/soci" class="font-semibold text-accent hover:underline">← Soci</Link></p>
        <h1 class="mb-1 text-2xl font-bold">Livelli di socio</h1>
        <p class="mb-6 text-sm text-muted">Per esempio «Socio junior» con una quota ridotta. Senza quota propria vale quella dell'associazione. «Vota» dice se chi ha quel livello ha diritto di voto in assemblea (i minorenni mai); un livello nuovo vota.</p>

        <div class="mb-8 space-y-3">
            <p v-if="!levels.length" class="text-sm text-muted">Nessun livello.</p>
            <form v-for="(l, i) in levels" :key="l.id" class="grid gap-3 card p-4 sm:grid-cols-[2fr_1.5fr_1fr_auto_auto_auto]" @submit.prevent="save(i)">
                <fieldset :disabled="!canEdit" class="contents">
                    <input v-model="edits[i].name" :class="field" aria-label="Nome" />
                    <select v-model="edits[i].base_type" :class="field" aria-label="Base"><option v-for="b in bases" :key="b.value" :value="b.value">{{ b.label }}</option></select>
                    <input v-model="edits[i].fee" inputmode="decimal" placeholder="Quota" :class="field" aria-label="Quota" />
                    <label class="flex items-center gap-2 text-sm"><input v-model="edits[i].active" type="checkbox" class="size-4 accent-indaco" /> attivo</label>
                    <label class="flex items-center gap-2 text-sm"><input v-model="edits[i].votes" type="checkbox" class="size-4 accent-indaco" /> vota</label>
                    <button v-if="canEdit" type="submit" class="btn btn-secondary btn-sm">Salva</button>
                </fieldset>
                <p class="text-xs text-muted sm:col-span-6">{{ l.people }} soci con questo livello</p>
            </form>
        </div>

        <form v-if="canEdit" class="grid gap-3 card p-4 sm:p-5 sm:grid-cols-[2fr_1.5fr_1fr_auto]" @submit.prevent="create">
            <h2 class="text-lg font-bold sm:col-span-4">Nuovo livello</h2>
            <input v-model="form.name" required placeholder="Nome" :class="field" aria-label="Nome del nuovo livello" />
            <select v-model="form.base_type" :class="field" aria-label="Base del nuovo livello"><option v-for="b in bases" :key="b.value" :value="b.value">{{ b.label }}</option></select>
            <input v-model="form.fee" inputmode="decimal" placeholder="Quota (facoltativa)" :class="field" aria-label="Quota del nuovo livello" />
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Crea</button>
        </form>
    </AppLayout>
</template>
