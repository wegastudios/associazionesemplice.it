<script setup>
import BrandLogo from '../components/BrandLogo.vue';

defineProps({ title: { type: String, required: true } });
</script>

<template>
    <main class="flex min-h-full items-start justify-center px-4 py-12 sm:items-center">
        <div class="w-full max-w-md">
            <div class="mb-8 flex justify-center"><BrandLogo /></div>
            <section class="rounded-2xl border border-line bg-card p-6 shadow-sm sm:p-8" :aria-labelledby="'page-title'">
                <h1 id="page-title" class="mb-6 text-xl font-bold">{{ title }}</h1>
                <slot />
            </section>
        </div>
    </main>
</template>
