<script setup>
defineProps({ title: { type: String, required: true }, subtitle: { type: String, default: null } });
</script>

<template>
    <main class="flex min-h-full flex-col items-center justify-start px-4 pb-[max(2rem,env(safe-area-inset-bottom))] pt-[max(2.5rem,env(safe-area-inset-top))] sm:justify-center">
        <div class="w-full max-w-md">
            <div class="mb-8 flex flex-col items-center gap-3 text-center">
                <picture>
                    <source srcset="/assets/brand/logo-orizzontale-negativo.svg" media="(prefers-color-scheme: dark)" />
                    <img src="/assets/brand/logo-orizzontale-colore.svg" alt="Associazione Semplice" class="h-11 w-auto" />
                </picture>
            </div>
            <section class="card p-5 sm:p-8" aria-labelledby="page-title">
                <h1 id="page-title" class="page-title">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1.5 text-[15px] text-muted">{{ subtitle }}</p>
                <div class="mt-6"><slot /></div>
            </section>
        </div>
    </main>
</template>
