<script setup>
import { Head, Link, useForm } from '@inertiajs/vue3';
import MemberLayout from '../../layouts/MemberLayout.vue';

const props = defineProps({ card: Object, details: Object, member: Object });
const form = useForm({ phone: props.details.phone ?? '', address: props.details.address ?? '' });
const own = props.member.profiles.find((p) => p.id === props.member.active)?.own ?? true;
const field = 'field';
</script>

<template>
    <Head title="Profilo" />
    <MemberLayout>
        <h1 class="mb-4 text-2xl font-bold">{{ card.first_name }} {{ card.last_name }}</h1>
        <section class="mb-6 card p-4 sm:p-5" aria-labelledby="d">
            <h2 id="d" class="mb-3 text-lg font-bold">I tuoi dati</h2>
            <dl class="grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div><dt class="text-muted">Email</dt><dd>{{ details.email ?? '—' }}</dd></div>
                <div><dt class="text-muted">Codice fiscale</dt><dd>{{ details.tax_code ?? '—' }}</dd></div>
                <div><dt class="text-muted">Nascita</dt><dd>{{ details.birth_date ?? '—' }}<template v-if="details.birth_place"> a {{ details.birth_place }}</template></dd></div>
                <div><dt class="text-muted">Socio dal</dt><dd>{{ card.joined_on ?? '—' }}</dd></div>
                <div v-if="details.guardians.length" class="sm:col-span-2"><dt class="text-muted">Genitori o tutori</dt><dd>{{ details.guardians.join(', ') }}</dd></div>
            </dl>
            <p class="mt-3 text-xs text-muted">Per correggere nome, email o codice fiscale scrivi alla segreteria.</p>
        </section>
        <form class="mb-6 card p-4 sm:p-5" @submit.prevent="form.put('/area/profilo', { preserveScroll: true })">
            <h2 class="mb-3 text-lg font-bold">Recapiti</h2>
            <div class="grid gap-4 sm:grid-cols-2">
                <label class="space-y-1 text-sm"><span class="font-semibold">Telefono</span><input v-model="form.phone" type="tel" autocomplete="tel" maxlength="40" :class="field" /></label>
                <label class="space-y-1 text-sm sm:col-span-2"><span class="font-semibold">Indirizzo</span><input v-model="form.address" autocomplete="street-address" maxlength="255" :class="field" /></label>
            </div>
            <button type="submit" class="mt-4 btn btn-primary" :disabled="form.processing">Salva</button>
        </form>
        <section v-if="details.memberships.length" class="mb-6 card p-4 sm:p-5" aria-labelledby="m">
            <h2 id="m" class="mb-3 text-lg font-bold">Tessere</h2>
            <ul class="divide-y divide-line text-sm">
                <li v-for="m in details.memberships" :key="m.social_year + m.valid_to" class="flex justify-between py-2" :class="m.valid ? '' : 'text-muted line-through'">
                    <span>Anno sociale {{ m.social_year }}</span><span>fino al {{ m.valid_to }}</span></li>
            </ul>
        </section>
        <section v-if="own" class="card p-4 sm:p-5 text-sm">
            <h2 class="mb-2 text-lg font-bold">Accessi</h2>
            <p class="mb-3 text-muted">Hai perso il telefono o sei entrato da un computer non tuo? Esci da tutti i dispositivi.</p>
            <Link href="/area/esci-ovunque" method="post" as="button" class="btn btn-danger">Esci da tutti i dispositivi</Link>
        </section>
    </MemberLayout>
</template>
