<script setup>
defineProps({
    id: { type: String, required: true },
    label: { type: String, required: true },
    error: { type: String, default: null },
    hint: { type: String, default: null },
});
</script>

<template>
    <div class="space-y-1.5">
        <label :for="id" class="block text-sm font-semibold">{{ label }}</label>
        <slot />
        <p v-if="hint && !error" :id="`${id}-hint`" class="text-sm text-muted">{{ hint }}</p>
        <p v-if="error" :id="`${id}-error`" class="text-sm font-semibold text-danger" role="alert">{{ error }}</p>
    </div>
</template>
