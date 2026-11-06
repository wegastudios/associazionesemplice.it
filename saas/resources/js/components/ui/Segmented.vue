<script setup>
const model = defineModel({ type: String, default: '' });
defineProps({ options: { type: Array, required: true }, label: { type: String, required: true } }); // [{ value, label }]
</script>

<template>
    <div class="inline-flex w-full rounded-xl bg-surface p-1 sm:w-auto" role="radiogroup" :aria-label="label">
        <button v-for="o in options" :key="o.value" type="button" role="radio" :aria-checked="model === o.value"
            class="min-h-10 flex-1 rounded-lg px-4 text-sm font-semibold transition-colors sm:flex-none"
            :class="model === o.value ? 'bg-card text-ink shadow-sm' : 'text-muted hover:text-ink'" @click="model = o.value">{{ o.label }}</button>
    </div>
</template>
