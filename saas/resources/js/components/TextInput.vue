<script setup>
const model = defineModel({ type: String, default: '' });
defineProps({
    id: { type: String, required: true },
    type: { type: String, default: 'text' },
    invalid: { type: Boolean, default: false },
});
</script>

<template>
    <input
        :id="id"
        v-model="model"
        :type="type"
        :aria-invalid="invalid ? 'true' : undefined"
        :aria-describedby="invalid ? `${id}-error` : undefined"
        class="field text-ink outline-none focus:border-accent focus:ring-2 focus:ring-accent/30 aria-[invalid=true]:border-danger"
    />
</template>
