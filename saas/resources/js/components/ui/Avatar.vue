<script setup>
import { computed } from 'vue';

const props = defineProps({ name: { type: String, default: '' }, size: { type: String, default: 'md' } });
const initials = computed(() => {
    const words = props.name.trim().split(/\s+/).filter(Boolean);
    return ((words[0]?.[0] ?? '') + (words.length > 1 ? words[words.length - 1][0] : '')).toUpperCase() || '·';
});
// Sempre lo stesso colore per lo stesso nome, preso dai tre colori del marchio.
const tint = computed(() => {
    let h = 0;
    for (const c of props.name) h = (h * 31 + c.charCodeAt(0)) >>> 0;
    return ['bg-info-soft text-info-ink', 'bg-ok-soft text-ok-ink', 'bg-warn-soft text-warn-ink'][h % 3];
});
const sizes = { sm: 'size-8 text-xs', md: 'size-10 text-sm', lg: 'size-14 text-lg' };
</script>

<template>
    <span :class="['grid shrink-0 place-items-center rounded-full font-bold', sizes[size] ?? sizes.md, tint]" aria-hidden="true">{{ initials }}</span>
</template>
