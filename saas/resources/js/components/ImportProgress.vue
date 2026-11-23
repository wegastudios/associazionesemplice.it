<script setup>
// Avanzamento di un import in coda (H10, H11): in coda, in corso con righe fatte su totale, finito con il riepilogo,
// interrotto (si riprende da dove si era fermato) o annullato. Mentre lavora, la pagina si aggiorna da sola.
import { router } from '@inertiajs/vue3';
import { computed, onUnmounted, watch } from 'vue';

const props = defineProps({
    job: Object,
    url: String, // es. /gestionale/soci/importa
    unit: { type: String, default: 'righe' }, // «righe» (femminile) o «movimenti» (maschile)
});

const f = computed(() => props.unit === 'righe');
const stopped = computed(() => props.job && (props.job.status === 'failed' || props.job.stalled));
const working = computed(() => props.job && ['queued', 'running'].includes(props.job.status) && !props.job.stalled);
const percent = computed(() => (props.job && props.job.total ? Math.floor((props.job.done * 100) / props.job.total) : 0));

let timer = null;
const stop = () => { if (timer) { clearInterval(timer); timer = null; } };
watch(working, (on) => {
    stop();
    if (on) timer = setInterval(() => router.reload({ only: ['job'] }), 2000);
}, { immediate: true });
onUnmounted(stop);

const post = (action) => router.post(`${props.url}/${props.job.id}/${action}`, {}, { preserveScroll: true });
</script>

<template>
    <section v-if="job" class="mb-6 card p-4 sm:p-5" role="status" aria-live="polite">
        <template v-if="stopped">
            <h2 class="mb-2 text-lg font-bold">Importazione interrotta</h2>
            <p v-if="f">Importate {{ job.done }} righe su {{ job.total }}. Riprendi per importare le altre: quelle già importate non si ripetono.</p>
            <p v-else>Importati {{ job.done }} movimenti su {{ job.total }}. Riprendi per importare gli altri: quelli già importati non si ripetono.</p>
            <div class="mt-3 text-sm"><slot :result="job.result" /></div>
            <button type="button" class="mt-3 btn btn-primary" @click="post('riprendi')">Riprendi l'importazione</button>
        </template>
        <template v-else-if="job.status === 'queued'">
            <h2 class="mb-2 text-lg font-bold">Importazione in coda</h2>
            <p>Parte entro un minuto. Puoi lasciare questa pagina: l'importazione va avanti da sola.</p>
            <button type="button" class="mt-3 btn btn-secondary" @click="post('annulla')">Annulla l'importazione</button>
        </template>
        <template v-else-if="job.status === 'running'">
            <h2 class="mb-2 text-lg font-bold">Importazione in corso</h2>
            <p>{{ job.done }} {{ unit }} su {{ job.total }} {{ f ? 'importate' : 'importati' }}.</p>
            <progress class="mt-2 w-full" :value="job.done" :max="job.total || 1" :aria-label="`${percent}%`">{{ percent }}%</progress>
            <p class="mt-2 text-sm text-muted">Puoi lasciare questa pagina: l'importazione va avanti da sola.</p>
        </template>
        <template v-else-if="job.status === 'cancelled'">
            <h2 class="mb-2 text-lg font-bold">Importazione annullata</h2>
            <p>Non è stato importato niente.</p>
        </template>
        <template v-else>
            <h2 class="mb-2 text-lg font-bold">Importazione completata</h2>
            <slot :result="job.result" />
        </template>
        <template v-if="job.status !== 'queued' && job.status !== 'cancelled' && job.result.failed.length">
            <p class="mt-3 font-semibold text-danger">Righe non importate:</p>
            <ul class="list-disc pl-5 text-sm"><li v-for="r in job.result.failed" :key="r">{{ r }}</li></ul>
        </template>
    </section>
</template>
