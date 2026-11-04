import { createApp, h } from 'vue';
import { createInertiaApp } from '@inertiajs/vue3';

const pages = import.meta.glob('./pages/**/*.vue', { eager: true });

createInertiaApp({
    title: (title) => (title ? `${title} · Associazione Semplice` : 'Associazione Semplice'),
    resolve: (name) => {
        const page = pages[`./pages/${name}.vue`];
        if (!page) {
            throw new Error(`Pagina inesistente: ${name}`);
        }
        return page;
    },
    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) }).use(plugin).mount(el);
    },
    progress: { color: '#EFCA5C' },
});
