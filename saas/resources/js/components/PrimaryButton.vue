<script setup>
defineProps({
    type: { type: String, default: 'submit' },
    disabled: { type: Boolean, default: false },
});
</script>

<template>
    <button
        :type="type"
        :disabled="disabled"
        class="inline-flex min-h-11 w-full items-center justify-center rounded-lg bg-accent px-4 py-2.5 font-semibold text-accent-ink transition hover:opacity-90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent disabled:opacity-60"
    >
        <slot />
    </button>
</template>
