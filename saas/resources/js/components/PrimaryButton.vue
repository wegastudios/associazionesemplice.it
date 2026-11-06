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
        class="btn btn-primary btn-block"
    >
        <slot />
    </button>
</template>
