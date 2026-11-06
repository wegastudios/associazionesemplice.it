<script setup>
defineProps({ title: { type: String, required: true }, text: { type: String, default: null } });
</script>

<template>
    <div class="px-6 py-10 text-center">
        <div v-if="$slots.icon" class="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-surface text-muted"><slot name="icon" /></div>
        <p class="font-semibold">{{ title }}</p>
        <p v-if="text" class="mx-auto mt-1 max-w-xs text-sm text-muted">{{ text }}</p>
        <div v-if="$slots.default" class="mt-4 flex justify-center"><slot /></div>
    </div>
</template>
