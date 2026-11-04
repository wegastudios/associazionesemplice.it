<script setup>
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import GuestLayout from '../../layouts/GuestLayout.vue';
import FormField from '../../components/FormField.vue';
import TextInput from '../../components/TextInput.vue';
import PrimaryButton from '../../components/PrimaryButton.vue';

const props = defineProps({
    enabled: Boolean,
    confirmed: Boolean,
    required: Boolean,
    qrSvg: { type: String, default: null },
    setupKey: { type: String, default: null },
    recoveryCodes: { type: Array, default: null },
});

const page = usePage();
const disableError = computed(() => page.props.errors?.two_factor ?? null);
const confirmForm = useForm({ code: '' });

const enable = () => router.post('/user/two-factor-authentication', {}, { preserveScroll: true });
const confirm = () =>
    confirmForm.post('/user/confirmed-two-factor-authentication', {
        errorBag: 'confirmTwoFactorAuthentication',
        preserveScroll: true,
        onFinish: () => confirmForm.reset(),
    });
const regenerate = () => router.post('/user/two-factor-recovery-codes', {}, { preserveScroll: true });
const disable = () => router.delete('/user/two-factor-authentication', { preserveScroll: true });
</script>

<template>
    <Head title="Verifica in due passaggi" />
    <GuestLayout title="Verifica in due passaggi">
        <p v-if="props.required && !props.confirmed" class="mb-5 rounded-lg border border-giallo bg-giallo/15 px-3 py-2 text-sm font-semibold">
            Per lavorare nel gestionale la verifica in due passaggi è obbligatoria: protegge i dati dei soci anche se
            qualcuno scopre la tua password.
        </p>

        <div v-if="!props.enabled" class="space-y-5">
            <p class="text-muted">
                A ogni accesso, oltre alla password, ti chiederemo un codice generato da un'app sul telefono
                (per esempio Google Authenticator, Microsoft Authenticator, 1Password).
            </p>
            <PrimaryButton type="button" @click="enable">Attiva la verifica</PrimaryButton>
        </div>

        <div v-else-if="!props.confirmed" class="space-y-5">
            <p class="text-muted">1. Inquadra il codice con l'app di autenticazione.</p>
            <div class="mx-auto w-fit rounded-lg bg-white p-3" aria-hidden="true" v-html="props.qrSvg" />
            <p class="text-sm text-muted">
                Non riesci a inquadrarlo? Inserisci a mano questa chiave:
                <code class="block break-all rounded bg-surface px-2 py-1 font-mono text-ink">{{ props.setupKey }}</code>
            </p>
            <form class="space-y-5" novalidate @submit.prevent="confirm">
                <FormField id="code" label="2. Scrivi il codice di 6 cifre che vedi nell'app" :error="confirmForm.errors.code">
                    <TextInput id="code" v-model="confirmForm.code" inputmode="numeric" autocomplete="one-time-code" maxlength="6" :invalid="!!confirmForm.errors.code" />
                </FormField>
                <PrimaryButton :disabled="confirmForm.processing">Conferma</PrimaryButton>
            </form>
        </div>

        <div v-else class="space-y-5">
            <p class="font-semibold">La verifica in due passaggi è attiva.</p>

            <div v-if="props.recoveryCodes" class="space-y-2">
                <p class="text-sm text-muted">
                    Salva questi codici di recupero in un posto sicuro (per esempio nel gestore delle password). Servono se
                    perdi il telefono; ognuno vale una sola volta. Non li mostreremo di nuovo.
                </p>
                <ul class="grid grid-cols-2 gap-2 rounded-lg bg-surface p-3 font-mono text-sm">
                    <li v-for="code in props.recoveryCodes" :key="code">{{ code }}</li>
                </ul>
            </div>

            <button type="button" class="text-sm font-semibold text-accent hover:underline" @click="regenerate">
                Genera nuovi codici di recupero
            </button>

            <div v-if="!props.required">
                <button type="button" class="text-sm font-semibold text-danger hover:underline" @click="disable">Disattiva la verifica</button>
            </div>
            <p v-if="disableError" class="text-sm font-semibold text-danger" role="alert">{{ disableError }}</p>

            <Link v-if="props.required" href="/gestionale" class="block text-center font-semibold text-accent hover:underline">Vai al gestionale</Link>
        </div>
    </GuestLayout>
</template>
