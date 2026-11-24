import { createApp, h } from 'vue';
import { createInertiaApp, router } from '@inertiajs/vue3';

// I font passano da Vite e finiscono nel manifest: app.blade.php precarica quello del testo con Vite::asset().
import.meta.glob('../fonts/*.woff2');

// Ogni pagina è un pacchetto a parte, scaricato quando serve: il primo caricamento porta solo ciò che si vede.
const pages = import.meta.glob('./pages/**/*.vue');

// Le voci del menu precaricano la pagina al passaggio del mouse. La copia vale solo fino al prossimo cambio di pagina
// (compresi invii di moduli e salvataggi): così non si mostra mai una pagina presa prima di una modifica.
router.on('navigate', () => router.flushAll());

createInertiaApp({
    title: (title) => (title ? `${title} · Associazione Semplice` : 'Associazione Semplice'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Pagina inesistente: ${name}`);
        }
        return page();
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#EFCA5C' },
});
