<script setup>
defineProps({ message: { type: String, default: null } });
</script>

<template>
    <p v-if="message" class="rounded-xl border border-salvia/30 bg-ok-soft px-4 py-3 text-sm font-semibold text-ok-ink" role="status">
        {{ message }}
    </p>
</template>
