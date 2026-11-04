<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const props = defineProps({ org: Object, sent: Boolean, needsRules: Boolean, types: Array, notices: Object, privacyUrl: String, rulesUrl: String });

const form = useForm({
    first_name: '', last_name: '', birth_date: '', birth_place: '', tax_code: '', email: '', phone: '', address: '', type: props.types[0]?.value ?? '',
    guardian_first_name: '', guardian_last_name: '', guardian_email: '', guardian_phone: '', guardian_tax_code: '', guardian_ok: false,
    privacy_ok: false, rules_ok: false, images_ok: false, website: '',
});

// Solo per mostrare i campi del genitore: la verifica vera la fa il server.
const minor = computed(() => {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(form.birth_date)) return false;
    const b = new Date(form.birth_date);
    const t = new Date();
    const age = t.getFullYear() - b.getFullYear() - (t < new Date(t.getFullYear(), b.getMonth(), b.getDate()) ? 1 : 0);
    return age < 18;
});
const errors = computed(() => Object.keys(form.errors).filter((k) => k.startsWith('form_')).map((k) => form.errors[k]));
const submit = () => form.post(`/adesione/${props.org.slug}`, { preserveScroll: true, onError: () => document.getElementById('form-errors')?.focus() });
</script>

<template>
    <Head :title="`Domanda di adesione · ${org.name}`" />
    <main class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="text-2xl font-bold">Domanda di adesione</h1>
        <p class="mb-6 text-muted">{{ org.name }}</p>

        <section v-if="sent" class="rounded-2xl border border-line bg-card p-6" role="status">
            <h2 class="mb-2 text-lg font-bold">Grazie, la domanda è arrivata.</h2>
            <p>La segreteria la esamina e ti risponde per email. Se non trovi la conferma, guarda nella posta indesiderata.</p>
        </section>

        <form v-else class="space-y-6 rounded-2xl border border-line bg-card p-6" novalidate @submit.prevent="submit">
            <div v-if="errors.length" id="form-errors" tabindex="-1" class="rounded-lg border border-danger/40 bg-danger/10 p-4 text-sm" role="alert">
                <p class="mb-1 font-semibold">Controlla questi punti:</p>
                <ul class="list-disc pl-5"><li v-for="e in errors" :key="e">{{ e }}</li></ul>
            </div>
            <p v-if="form.errors.website" class="text-sm text-danger">{{ form.errors.website }}</p>

            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 font-bold">Chi si iscrive</legend>
                <FormField id="first_name" label="Nome"><TextInput id="first_name" v-model="form.first_name" autocomplete="given-name" required /></FormField>
                <FormField id="last_name" label="Cognome"><TextInput id="last_name" v-model="form.last_name" autocomplete="family-name" required /></FormField>
                <FormField id="birth_date" label="Data di nascita"><TextInput id="birth_date" v-model="form.birth_date" type="date" autocomplete="bday" required /></FormField>
                <FormField id="birth_place" label="Luogo di nascita"><TextInput id="birth_place" v-model="form.birth_place" /></FormField>
                <FormField id="tax_code" label="Codice fiscale"><TextInput id="tax_code" v-model="form.tax_code" autocapitalize="characters" maxlength="16" required /></FormField>
                <FormField id="type" label="Tipo di socio">
                    <select id="type" v-model="form.type" class="block w-full rounded-lg border border-line bg-card px-3 py-2.5 text-base">
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </FormField>
                <FormField id="email" label="Email" :hint="minor ? 'Facoltativa per un minore' : null"><TextInput id="email" v-model="form.email" type="email" autocomplete="email" /></FormField>
                <FormField id="phone" label="Cellulare" :hint="minor ? 'Facoltativo per un minore' : null"><TextInput id="phone" v-model="form.phone" type="tel" autocomplete="tel" /></FormField>
                <div class="sm:col-span-2"><FormField id="address" label="Indirizzo"><TextInput id="address" v-model="form.address" autocomplete="street-address" /></FormField></div>
            </fieldset>

            <fieldset v-if="minor" class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 font-bold">Genitore o tutore</legend>
                <p class="text-sm text-muted sm:col-span-2">Chi ha meno di 18 anni si iscrive con un genitore o tutore: le comunicazioni arrivano a lui.</p>
                <FormField id="guardian_first_name" label="Nome"><TextInput id="guardian_first_name" v-model="form.guardian_first_name" required /></FormField>
                <FormField id="guardian_last_name" label="Cognome"><TextInput id="guardian_last_name" v-model="form.guardian_last_name" required /></FormField>
                <FormField id="guardian_email" label="Email"><TextInput id="guardian_email" v-model="form.guardian_email" type="email" required /></FormField>
                <FormField id="guardian_phone" label="Cellulare"><TextInput id="guardian_phone" v-model="form.guardian_phone" type="tel" required /></FormField>
                <FormField id="guardian_tax_code" label="Codice fiscale (facoltativo)"><TextInput id="guardian_tax_code" v-model="form.guardian_tax_code" maxlength="16" /></FormField>
                <label class="flex items-start gap-2 text-sm sm:col-span-2"><input v-model="form.guardian_ok" type="checkbox" class="mt-1" /> Dichiaro di essere il genitore o il tutore e di presentare la domanda per suo conto.</label>
            </fieldset>

            <fieldset class="space-y-3 text-sm">
                <legend class="mb-2 font-bold">Consensi</legend>
                <label class="flex items-start gap-2"><input v-model="form.privacy_ok" type="checkbox" class="mt-1" /> <span>{{ notices.privacy }} <a v-if="privacyUrl" :href="privacyUrl" target="_blank" rel="noopener" class="font-semibold text-accent hover:underline">Leggi l'informativa</a> (obbligatorio)</span></label>
                <label v-if="needsRules" class="flex items-start gap-2"><input v-model="form.rules_ok" type="checkbox" class="mt-1" /> <span>{{ notices.rules }} <a v-if="rulesUrl" :href="rulesUrl" target="_blank" rel="noopener" class="font-semibold text-accent hover:underline">Leggi il regolamento</a> (obbligatorio)</span></label>
                <label class="flex items-start gap-2"><input v-model="form.images_ok" type="checkbox" class="mt-1" /> <span>{{ notices.images }} (facoltativo)</span></label>
            </fieldset>

            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Lascia vuoto questo campo</label>
                <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
            </div>

            <PrimaryButton :disabled="form.processing">Manda la domanda</PrimaryButton>
        </form>
    </main>
</template>
