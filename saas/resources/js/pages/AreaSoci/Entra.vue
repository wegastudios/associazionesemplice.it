<script setup>
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps({ orgName: String, token: String });
const form = useForm({});
</script>

<template>
    <Head :title="`Area soci · ${orgName}`" />
    <main class="flex min-h-full items-start justify-center px-4 py-12 sm:items-center">
        <div class="w-full max-w-md">
            <p class="mb-6 text-center text-lg font-bold">{{ orgName }}</p>
            <section class="card p-5 shadow-sm sm:p-8" aria-labelledby="t">
                <h1 id="t" class="mb-2 text-xl font-bold">Entra nell'area soci</h1>
                <p class="mb-6 text-sm text-muted">Premi il pulsante per entrare.</p>
                <form @submit.prevent="form.post(`/area/entra/${token}`)">
                    <button type="submit" class="btn-block btn btn-primary" :disabled="form.processing">Entra</button>
                </form>
            </section>
        </div>
    </main>
</template>
