<script setup>
import { nextTick, onBeforeUnmount, ref, watch } from 'vue';
import { X } from './icons.js';

const open = defineModel({ type: Boolean, default: false });
const props = defineProps({ title: { type: String, required: true }, description: { type: String, default: null } });
const panel = ref(null);
let opener = null;
const titleId = `sheet-${Math.random().toString(36).slice(2, 8)}`;

const close = () => { open.value = false; };
const onKey = (e) => {
    if (e.key === 'Escape') close();
    if (e.key !== 'Tab' || !panel.value) return;
    const items = [...panel.value.querySelectorAll('a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')];
    if (!items.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
};

watch(open, async (v) => {
    if (v) {
        opener = document.activeElement;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', onKey);
        await nextTick();
        panel.value?.querySelector('[data-autofocus], a[href], button:not([data-close])')?.focus();
    } else {
        document.body.style.overflow = '';
        document.removeEventListener('keydown', onKey);
        opener?.focus?.();
    }
});
onBeforeUnmount(() => {
    document.body.style.overflow = '';
    document.removeEventListener('keydown', onKey);
});
</script>

<template>
    <Teleport to="body">
        <Transition name="sheet">
            <div v-if="open" class="fixed inset-0 z-50 flex items-end justify-center bg-ink/45 md:items-center md:p-6" @click.self="close">
                <div ref="panel" class="sheet-panel flex max-h-[88dvh] w-full flex-col rounded-t-3xl bg-card text-ink shadow-sheet md:max-w-lg md:rounded-3xl"
                    role="dialog" aria-modal="true" :aria-labelledby="titleId">
                    <div class="mx-auto mt-2 h-1 w-10 rounded-full bg-line md:hidden" aria-hidden="true" />
                    <header class="flex items-start gap-3 px-5 pb-2 pt-4">
                        <div class="min-w-0 flex-1">
                            <h2 :id="titleId" class="text-lg font-bold leading-6">{{ props.title }}</h2>
                            <p v-if="props.description" class="caption mt-0.5">{{ props.description }}</p>
                        </div>
                        <button type="button" data-close class="-mr-2 grid size-11 shrink-0 place-items-center rounded-full text-muted hover:bg-surface" aria-label="Chiudi" @click="close"><X class="size-5" /></button>
                    </header>
                    <div class="overflow-y-auto overscroll-contain px-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] pt-2"><slot /></div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
