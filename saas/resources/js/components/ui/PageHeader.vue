<script setup>
import { Link } from '@inertiajs/vue3';
import { ChevronLeft } from './icons.js';

defineProps({
    title: { type: String, required: true },
    subtitle: { type: String, default: null },
    back: { type: Object, default: null }, // { href, label }
});
</script>

<template>
    <header class="mb-5">
        <Link v-if="back" :href="back.href" class="-ml-1 mb-1 inline-flex min-h-9 items-center gap-0.5 rounded-lg pr-2 text-sm font-semibold text-accent hover:underline">
            <ChevronLeft class="size-4" aria-hidden="true" />{{ back.label }}
        </Link>
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div class="min-w-0 flex-1">
                <h1 class="page-title">{{ title }}</h1>
                <p v-if="subtitle" class="mt-1 text-[15px] text-muted">{{ subtitle }}</p>
            </div>
            <div v-if="$slots.actions" class="flex flex-wrap items-center gap-2"><slot name="actions" /></div>
        </div>
    </header>
</template>
