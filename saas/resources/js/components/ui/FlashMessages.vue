<script setup>
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { CircleAlert, CircleCheck, X } from './icons.js';

const page = usePage();
const toast = ref(null);
let timer = null;
const error = computed(() => page.props.errors?.ledger ?? null);
const dismissed = ref(null);

// Il messaggio di conferma compare per qualche secondo e si chiude toccandolo. L'errore resta finché non si corregge.
watch(() => page.props.flash, (f) => {
    clearTimeout(timer);
    toast.value = f?.status ? { text: f.status, id: Date.now() } : null;
    if (toast.value) timer = setTimeout(() => { toast.value = null; }, Math.min(9000, 3500 + toast.value.text.length * 45));
}, { immediate: true });
watch(error, () => { dismissed.value = null; });
onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <div v-if="error && dismissed !== error" class="mb-4 flex items-start gap-3 rounded-xl border border-danger/30 bg-danger-soft px-4 py-3 text-sm font-semibold text-danger" role="alert">
        <CircleAlert class="mt-0.5 size-5 shrink-0" aria-hidden="true" />
        <p class="flex-1">{{ error }}</p>
        <button type="button" class="-mr-2 -mt-1 grid size-9 place-items-center rounded-full hover:bg-danger/10" aria-label="Chiudi l'avviso" @click="dismissed = error"><X class="size-4" /></button>
    </div>
    <div class="pointer-events-none fixed inset-x-4 bottom-[calc(4.75rem+env(safe-area-inset-bottom))] z-40 flex justify-center lg:inset-x-auto lg:bottom-6 lg:right-6 lg:justify-end" aria-live="polite">
        <Transition name="toast">
            <button v-if="toast" :key="toast.id" type="button" class="pointer-events-auto flex w-full max-w-md items-start gap-3 rounded-xl bg-ink px-4 py-3 text-left text-sm font-semibold text-surface shadow-sheet"
                @click="toast = null">
                <CircleCheck class="mt-0.5 size-5 shrink-0 text-salvia-chiaro" aria-hidden="true" />
                <span class="flex-1">{{ toast.text }}</span>
            </button>
        </Transition>
    </div>
</template>
