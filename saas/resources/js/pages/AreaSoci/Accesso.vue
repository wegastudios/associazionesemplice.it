<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ orgName: String, step: String, email: String, message: String });
const ask = useForm({ email: props.email ?? '' });
const code = useForm({ email: props.email ?? '', code: '' });
const field = 'block w-full rounded-lg border border-line bg-card px-3 py-2 text-ink';
</script>

<template>
    <Head :title="`Area soci · ${orgName}`" />
    <main class="flex min-h-full items-start justify-center px-4 py-12 sm:items-center">
        <div class="w-full max-w-md">
            <p class="mb-6 text-center text-lg font-bold">{{ orgName }}</p>
            <section class="rounded-2xl border border-line bg-card p-6 shadow-sm sm:p-8" aria-labelledby="t">
                <h1 id="t" class="mb-2 text-xl font-bold">Entra nell'area soci</h1>
                <p class="mb-6 text-sm text-muted">Niente password: ti mandiamo per email un link e un codice.</p>
                <p v-if="message" class="mb-4 rounded-lg border border-salvia/40 bg-salvia/10 px-3 py-2 text-sm" role="status">{{ message }}</p>
                <form v-if="step !== 'code'" class="space-y-4" @submit.prevent="ask.post('/area/accesso')">
                    <label class="block space-y-1 text-sm"><span class="font-semibold">La tua email</span>
                        <input v-model="ask.email" type="email" autocomplete="email" required :class="field" /></label>
                    <p v-if="ask.errors.email" class="text-sm text-danger" role="alert">{{ ask.errors.email }}</p>
                    <button type="submit" class="w-full rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="ask.processing">Mandami il link</button>
                </form>
                <template v-else>
                    <form class="space-y-4" @submit.prevent="code.post('/area/accesso/codice')">
                        <label class="block space-y-1 text-sm"><span class="font-semibold">Codice a 6 cifre</span>
                            <input v-model="code.code" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="one-time-code" required :class="field" /></label>
                        <p v-if="code.errors.code || code.errors.email" class="text-sm text-danger" role="alert">{{ code.errors.code || code.errors.email }}</p>
                        <button type="submit" class="w-full rounded-lg bg-accent px-4 py-2 font-semibold text-accent-ink" :disabled="code.processing">Entra</button>
                    </form>
                    <form class="mt-4 text-center text-sm" @submit.prevent="ask.post('/area/accesso')">
                        <button type="submit" class="text-accent hover:underline">Mandami un altro codice</button>
                    </form>
                </template>
                <p class="mt-6 text-xs text-muted">Non hai un'email registrata? Chiedi alla segreteria un link d'accesso.</p>
            </section>
        </div>
    </main>
</template>
