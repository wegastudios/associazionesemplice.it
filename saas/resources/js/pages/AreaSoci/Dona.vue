<script setup>
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const props = defineProps({ org: Object, amount: String, providers: Array, deductible: Boolean, privacyUrl: String, contacts: Object });
const page = usePage();
const form = useForm({ first_name: '', last_name: '', email: '', tax_code: '', amount: props.amount, provider: props.providers[0] ?? '', privacy_ok: false, website: '' });
const status = computed(() => page.props.flash?.status ?? page.props.status ?? null);
const submit = () => form.post(`/dona/${props.org.slug}`, { preserveScroll: true });
</script>

<template>
    <Head :title="`Fai una donazione · ${org.name}`" />
    <main class="mx-auto max-w-xl px-4 py-10">
        <h1 class="text-2xl font-bold">Fai una donazione</h1>
        <p class="mb-6 text-muted">{{ org.name }}</p>
        <p v-if="status" class="mb-4 rounded-lg bg-surface p-3 text-sm" role="status">{{ status }}</p>

        <form class="space-y-6 card p-5 sm:p-6" novalidate @submit.prevent="submit">
            <fieldset class="grid gap-4 sm:grid-cols-2">
                <legend class="mb-2 font-bold">Chi dona</legend>
                <FormField id="first_name" label="Nome" :error="form.errors.first_name"><TextInput id="first_name" v-model="form.first_name" autocomplete="given-name" required /></FormField>
                <FormField id="last_name" label="Cognome" :error="form.errors.first_name ? undefined : form.errors.last_name"><TextInput id="last_name" v-model="form.last_name" autocomplete="family-name" required /></FormField>
                <div class="sm:col-span-2"><FormField id="email" label="Email" :error="form.errors.email"><TextInput id="email" v-model="form.email" type="email" autocomplete="email" required /></FormField>
                    <p class="caption mt-1">Ti mandiamo qui la ricevuta.</p></div>
                <div class="sm:col-span-2"><FormField id="tax_code" label="Codice fiscale (facoltativo)" :error="form.errors.tax_code"><TextInput id="tax_code" v-model="form.tax_code" maxlength="16" autocomplete="off" /></FormField>
                    <p class="caption mt-1">Scrivilo se vuoi che compaia nella ricevuta.</p></div>
            </fieldset>

            <fieldset class="space-y-3">
                <legend class="mb-2 font-bold">Donazione</legend>
                <FormField id="amount" label="Importo (€)" :error="form.errors.amount"><TextInput id="amount" v-model="form.amount" inputmode="decimal" required /></FormField>
                <p class="caption">Da 1 € a 1.000 €. Non paghi commissioni in più.</p>
                <p v-if="deductible" class="text-sm">È anche detraibile.</p>
                <div class="space-y-2 text-sm" role="radiogroup" aria-label="Come vuoi pagare">
                    <label v-if="providers.includes('stripe')" class="flex items-center gap-2"><input v-model="form.provider" type="radio" value="stripe" /> Carta</label>
                    <label v-if="providers.includes('paypal')" class="flex items-center gap-2"><input v-model="form.provider" type="radio" value="paypal" /> PayPal</label>
                    <p v-if="form.errors.provider" class="font-semibold text-danger" role="alert">{{ form.errors.provider }}</p>
                </div>
            </fieldset>

            <div class="space-y-2 text-sm">
                <label class="flex items-start gap-2"><input v-model="form.privacy_ok" type="checkbox" class="mt-1" /> <span>Ho letto l'informativa sul trattamento dei dati personali di {{ org.name }}. <a v-if="privacyUrl" :href="privacyUrl" target="_blank" rel="noopener" class="font-semibold text-accent hover:underline">Leggi l'informativa</a></span></label>
                <p class="caption">I dati servono a emettere la ricevuta della donazione e per gli obblighi contabili.</p>
                <p v-if="form.errors.privacy_ok" class="font-semibold text-danger" role="alert">{{ form.errors.privacy_ok }}</p>
                <p v-if="contacts" class="caption">Per esercitare i tuoi diritti sui dati scrivi a {{ contacts.email }} o chiama {{ contacts.phone }}.</p>
            </div>

            <div class="absolute -left-[9999px]" aria-hidden="true">
                <label for="website">Lascia vuoto questo campo</label>
                <input id="website" v-model="form.website" type="text" tabindex="-1" autocomplete="off" />
            </div>

            <PrimaryButton :disabled="form.processing">Dona</PrimaryButton>
            <p class="caption">Paghi sulla pagina sicura del servizio scelto: l'associazione non vede i dati della carta.</p>
        </form>
    </main>
</template>
