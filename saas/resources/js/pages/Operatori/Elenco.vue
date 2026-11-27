<script setup>
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppLayout from '../../layouts/AppLayout.vue';

const props = defineProps({ operators: Array, roles: Array, fixedRoles: Array, abilities: Array, removable: Array, twoFactorRequired: Boolean, twoFactorMembersRequired: Boolean });
const memberArea = computed(() => (usePage().props.org?.packages ?? []).includes('member_area'));
const setMembersRequired = (on) => {
    if (on && !window.confirm('Chiedere la verifica in due passaggi ai soci? Chi non l\'ha ancora accesa dovrà accenderla al prossimo ingresso nell\'area soci, prima di vedere il resto.')) return;
    router.put('/gestionale/operatori/verifica-soci', { required: on }, { preserveScroll: true });
};

// Verifica in due passaggi obbligatoria per chi lavora qui: per chiederla bisogna averla attiva.
const meHasTwoFactor = computed(() => props.operators.some((o) => o.me && o.two_factor));
const withoutTwoFactor = computed(() => props.operators.filter((o) => !o.two_factor).length);
const setTwoFactorRequired = (on) => {
    if (on && !window.confirm(withoutTwoFactor.value === 0
        ? 'Rendere obbligatoria la verifica in due passaggi per chi lavora nell\'associazione?'
        : `Rendere obbligatoria la verifica in due passaggi? ${withoutTwoFactor.value === 1 ? '1 persona non l\'ha ancora attiva: riceverà un\'email e dovrà attivarla' : `${withoutTwoFactor.value} persone non l'hanno ancora attiva: riceveranno un'email e dovranno attivarla`} al prossimo accesso, prima di vedere i dati.`)) return;
    router.put('/gestionale/operatori/verifica-obbligatoria', { required: on }, { preserveScroll: true });
};

// Dipendente: un interruttore per ogni permesso toglibile; ogni cambio si salva subito.
const setDenied = (o, value, allowed) => {
    const denied = new Set(o.denied);
    if (allowed) denied.delete(value); else denied.add(value);
    router.put(`/gestionale/operatori/${o.user_id}/permessi`, { denied: [...denied] }, { preserveScroll: true });
};

// Un valore per la scelta del ruolo: «operator» per i ruoli fissi, «custom:<id>» per quelli dell'associazione.
const choices = computed(() => [
    ...props.fixedRoles.map((r) => ({ value: r.value, label: r.label })),
    ...props.roles.map((r) => ({ value: `custom:${r.id}`, label: r.name })),
]);
const split = (v) => (v.startsWith('custom:') ? { role: 'custom', custom_role_id: v.slice(7) } : { role: v, custom_role_id: null });
const valueOf = (o) => (o.role === 'custom' ? `custom:${o.custom_role_id}` : o.role);

const invite = useForm({ email: '', name: '', choice: 'operator' });
const sendInvite = () => invite.transform((d) => ({ email: d.email, name: d.name, ...split(d.choice) }))
    .post('/gestionale/operatori', { preserveScroll: true, onSuccess: () => invite.reset() });
const changeRole = (o, v) => router.put(`/gestionale/operatori/${o.user_id}`, split(v), { preserveScroll: true });
const remove = (o) => { if (confirm(`Togliere ${o.name} dagli operatori? Non potrà più entrare nel gestionale di questa associazione.`)) router.delete(`/gestionale/operatori/${o.user_id}`, { preserveScroll: true }); };

const editing = ref(null);
const role = useForm({ name: '', abilities: [] });
const startRole = (r) => {
    editing.value = r ? r.id : 'new';
    role.name = r?.name ?? '';
    role.abilities = r ? [...r.abilities] : ['people.view'];
    role.clearErrors();
};
// Scegliendo un permesso si aggiungono quelli che presuppone (chi modifica deve anche vedere).
const toggle = (a, on) => {
    const set = new Set(role.abilities);
    if (on) { set.add(a.value); a.requires.forEach((r) => set.add(r)); } else {
        set.delete(a.value);
        props.abilities.filter((x) => x.requires.includes(a.value)).forEach((x) => set.delete(x.value));
    }
    role.abilities = [...set];
};
const saveRole = () => (editing.value === 'new'
    ? role.post('/gestionale/ruoli', { preserveScroll: true, onSuccess: () => { editing.value = null; } })
    : role.put(`/gestionale/ruoli/${editing.value}`, { preserveScroll: true, onSuccess: () => { editing.value = null; } }));
const deleteRole = (r) => window.confirm(`Eliminare il ruolo «${r.name}»? È definitivo: non si può recuperare.`) && router.delete(`/gestionale/ruoli/${r.id}`, { preserveScroll: true });
const abilityLabel = (v) => props.abilities.find((a) => a.value === v)?.label ?? v;

const field = 'field';
</script>

<template>
    <Head title="Operatori" />
    <AppLayout>
        <h1 class="mb-1 text-2xl font-bold">Operatori e ruoli</h1>
        <p class="mb-6 text-sm text-muted">Chi entra nel gestionale e cosa può fare.</p>

        <section class="mb-6 card p-4 sm:p-5" aria-labelledby="security-title">
            <h2 id="security-title" class="mb-2 text-lg font-bold">Sicurezza</h2>
            <label class="flex items-start gap-2 text-sm">
                <input type="checkbox" class="mt-1" :checked="twoFactorRequired" :disabled="!twoFactorRequired && !meHasTwoFactor" @change="setTwoFactorRequired($event.target.checked)" />
                <span>
                    <span class="font-semibold">Verifica in due passaggi obbligatoria per chi lavora nell'associazione</span>
                    <span class="block text-muted">Oltre alla password, a ogni accesso serve un codice dal telefono. Chi non l'ha ancora attiva la attiva al prossimo accesso, prima di vedere i dati. Spenta, ognuno sceglie per sé.</span>
                    <span v-if="!twoFactorRequired && !meHasTwoFactor" class="block text-muted">Per chiederla a tutti, prima <a href="/account/verifica-in-due-passaggi" class="font-semibold text-accent hover:underline">attivala per te</a>.</span>
                    <span v-else-if="twoFactorRequired && withoutTwoFactor" class="block text-muted">{{ withoutTwoFactor === 1 ? '1 persona non l\'ha ancora attiva.' : `${withoutTwoFactor} persone non l'hanno ancora attiva.` }}</span>
                </span>
            </label>
            <label v-if="memberArea" class="mt-3 flex items-start gap-2 text-sm">
                <input type="checkbox" class="mt-1" :checked="twoFactorMembersRequired" @change="setMembersRequired($event.target.checked)" />
                <span>
                    <span class="font-semibold">Richiedi la verifica in due passaggi ai soci</span>
                    <span class="block text-muted">Nell'area soci, oltre al link o al codice via email, serve un codice dal telefono. Chi non l'ha ancora accesa la accende al prossimo ingresso. Spenta, ogni socio sceglie per sé.</span>
                </span>
            </label>
        </section>

        <section class="card p-4 sm:p-5" aria-labelledby="ops-title">
            <h2 id="ops-title" class="mb-3 text-lg font-bold">Operatori</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="o in operators" :key="o.user_id" class="flex flex-wrap items-center gap-3 py-3">
                    <div class="min-w-48">
                        <p class="font-semibold">{{ o.name }}<span v-if="o.me" class="ml-1 text-muted">(tu)</span></p>
                        <p class="text-muted">{{ o.email }}<span v-if="!o.two_factor"> · verifica in due passaggi non attiva</span></p>
                    </div>
                    <select v-if="o.editable" :value="valueOf(o)" class="field w-auto" :aria-label="`Ruolo di ${o.name}`" @change="changeRole(o, $event.target.value)">
                        <option v-for="c in choices" :key="c.value" :value="c.value">{{ c.label }}</option>
                    </select>
                    <span v-else class="rounded bg-surface px-2 py-1">{{ o.role_label }}</span>
                    <button v-if="o.editable" type="button" class="ml-auto text-danger hover:underline" @click="remove(o)">Togli</button>
                    <fieldset v-if="o.role === 'employee' && o.editable" class="basis-full rounded border border-line p-3">
                        <legend class="px-1 text-xs font-semibold text-muted">Cosa può fare {{ o.name }}</legend>
                        <label v-for="r in removable" :key="r.value" class="mr-4 inline-flex items-center gap-2"><input type="checkbox" :checked="!o.denied.includes(r.value)" @change="setDenied(o, r.value, $event.target.checked)" />{{ r.label }}</label>
                    </fieldset>
                </li>
            </ul>
            <form class="mt-4 grid gap-3 border-t border-line pt-4 text-sm sm:grid-cols-[2fr_2fr_2fr_auto]" @submit.prevent="sendInvite">
                <p class="font-semibold sm:col-span-4">Invita un operatore: riceverà un'email di invito.</p>
                <label class="space-y-1"><span class="block font-semibold">Nome</span><input v-model="invite.name" :class="field" required maxlength="120" /></label>
                <label class="space-y-1"><span class="block font-semibold">Email</span><input v-model="invite.email" type="email" :class="field" required /></label>
                <label class="space-y-1"><span class="block font-semibold">Ruolo</span>
                    <select v-model="invite.choice" :class="field"><option v-for="c in choices" :key="c.value" :value="c.value">{{ c.label }}</option></select></label>
                <button type="submit" class="self-end btn btn-primary" :disabled="invite.processing">Manda l'invito</button>
                <p v-if="invite.errors.email" class="text-danger sm:col-span-4">{{ invite.errors.email }}</p>
            </form>
        </section>

        <section class="mt-6 card p-4 sm:p-5" aria-labelledby="roles-title">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
                <h2 id="roles-title" class="text-lg font-bold">Ruoli dell'associazione</h2>
                <button v-if="editing === null" type="button" class="btn btn-secondary btn-sm" @click="startRole(null)">Nuovo ruolo</button>
            </div>
            <p class="mb-3 text-sm text-muted">Ruoli su misura (segretario, consigliere, tesoriere…) con i permessi che scegli. Abbonamento, impostazioni e operatori restano a titolare e amministratore.</p>

            <form v-if="editing !== null" class="mb-4 space-y-3 rounded-lg border border-line p-4 text-sm" @submit.prevent="saveRole">
                <label class="block max-w-md space-y-1"><span class="font-semibold">Nome del ruolo</span><input v-model="role.name" :class="field" required maxlength="60" placeholder="es. Consigliere" /></label>
                <fieldset><legend class="mb-1 font-semibold">Permessi</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label v-for="a in abilities" :key="a.value" class="flex items-center gap-2"><input type="checkbox" :checked="role.abilities.includes(a.value)" @change="toggle(a, $event.target.checked)" /> {{ a.label }}</label>
                    </div>
                </fieldset>
                <div class="flex gap-3">
                    <button type="submit" class="btn btn-primary" :disabled="role.processing">Salva il ruolo</button>
                    <button type="button" class="btn btn-secondary" @click="editing = null">Chiudi</button>
                </div>
            </form>

            <p v-if="!roles.length && editing === null" class="text-sm text-muted">Nessun ruolo dell'associazione.</p>
            <ul class="divide-y divide-line text-sm">
                <li v-for="r in roles" :key="r.id" class="py-3">
                    <div class="flex flex-wrap items-center gap-3">
                        <span class="font-semibold">{{ r.name }}</span>
                        <span class="text-muted">{{ r.members === 1 ? '1 operatore' : `${r.members} operatori` }}</span>
                        <span class="ml-auto flex gap-3">
                            <button type="button" class="text-accent hover:underline" @click="startRole(r)">Modifica</button>
                            <button v-if="!r.members" type="button" class="text-danger hover:underline" @click="deleteRole(r)">Elimina</button>
                        </span>
                    </div>
                    <p class="mt-1 text-muted">{{ r.abilities.map(abilityLabel).join(' · ') }}</p>
                </li>
            </ul>
        </section>

        <section class="mt-6 card p-4 sm:p-5" aria-labelledby="fixed-title">
            <h2 id="fixed-title" class="mb-3 text-lg font-bold">Ruoli fissi</h2>
            <dl class="space-y-2 text-sm">
                <div v-for="r in fixedRoles" :key="r.value"><dt class="font-semibold">{{ r.label }}</dt><dd class="text-muted">{{ r.abilities.join(' · ') }}</dd></div>
            </dl>
        </section>
    </AppLayout>
</template>
