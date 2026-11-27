<script setup>
import { Head, useForm } from '@inertiajs/vue3';

defineProps({ orgName: String, message: { type: String, default: null } });
const form = useForm({ code: '' });
const reset = useForm({});
const field = 'field';
</script>

<template>
    <Head :title="`Area soci · ${orgName}`" />
    <main class="flex min-h-full items-start justify-center px-4 py-12 sm:items-center">
        <div class="w-full max-w-md">
            <p class="mb-6 text-center text-lg font-bold">{{ orgName }}</p>
            <section class="card p-5 shadow-sm sm:p-8" aria-labelledby="t">
                <h1 id="t" class="mb-2 text-xl font-bold">Verifica in due passaggi</h1>
                <p class="mb-6 text-sm text-muted">Scrivi il codice di 6 cifre che vedi adesso nell'app sul telefono, oppure un codice di riserva.</p>
                <p v-if="message" class="mb-4 rounded-lg border border-salvia/40 bg-salvia/10 px-3 py-2 text-sm" role="status">{{ message }}</p>
                <form class="space-y-4" @submit.prevent="form.post('/area/accesso/verifica', { onFinish: () => form.reset('code') })">
                    <label class="block space-y-1 text-sm"><span class="font-semibold">Codice dell'app o codice di riserva</span>
                        <input v-model="form.code" inputmode="numeric" maxlength="20" autocomplete="one-time-code" required autofocus :class="field" /></label>
                    <p v-if="form.errors.code" class="text-sm text-danger" role="alert">{{ form.errors.code }}</p>
                    <button type="submit" class="btn-block btn btn-primary" :disabled="form.processing">Entra</button>
                </form>
                <details class="mt-6 border-t border-line pt-4 text-sm">
                    <summary class="cursor-pointer font-semibold">Hai perso il telefono e i codici di riserva?</summary>
                    <p class="mt-2 text-muted">Ti mandiamo per email un link che spegne la verifica (vale 15 minuti). Avviseremo anche la segreteria.</p>
                    <form class="mt-3" @submit.prevent="reset.post('/area/accesso/verifica/ripristino')">
                        <button type="submit" class="btn btn-secondary" :disabled="reset.processing">Mandami il link</button>
                    </form>
                    <p class="mt-3 text-muted">Non ricevi l'email? Chiedi alla segreteria di spegnere la verifica.</p>
                </details>
            </section>
        </div>
    </main>
</template>
