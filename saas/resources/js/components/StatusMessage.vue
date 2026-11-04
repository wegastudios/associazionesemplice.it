<script setup>
defineProps({ message: { type: String, default: null } });
</script>

<template>
    <p v-if="message" class="rounded-lg border border-salvia/40 bg-salvia/10 px-3 py-2 text-sm font-semibold" role="status">
        {{ message }}
    </p>
</template>
