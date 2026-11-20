<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ org: Object, token: String, done: Boolean });
const form = useForm({});
const confirm = () => form.post(`/adesione/${props.org.slug}/conferma/${props.token}`);
</script>

<template>
    <Head :title="`Conferma la domanda · ${org.name}`" />
    <main class="mx-auto max-w-2xl px-4 py-10">
        <h1 class="text-2xl font-bold">Domanda di adesione</h1>
        <p class="mb-6 text-muted">{{ org.name }}</p>

        <section v-if="done" class="card p-5 sm:p-6" role="status">
            <h2 class="mb-2 text-lg font-bold">Domanda confermata.</h2>
            <p>La segreteria la esamina e ti risponde per email.</p>
        </section>

        <section v-else class="card p-5 sm:p-6">
            <h2 class="mb-2 text-lg font-bold">Conferma la domanda</h2>
            <p class="mb-4">Premi il pulsante per mandare la domanda alla segreteria.</p>
            <p v-if="form.errors.confirm" class="mb-4 text-sm text-danger" role="alert">
                {{ form.errors.confirm }}
                <a :href="`/adesione/${org.slug}`" class="font-semibold text-accent hover:underline">Compila il modulo</a>
            </p>
            <form @submit.prevent="confirm">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Conferma la domanda</button>
            </form>
        </section>
    </main>
</template>
