import '../css/app.css';

import { createInertiaApp } from '@inertiajs/vue3';
import { resolvePageComponent } from 'laravel-vite-plugin/inertia-helpers';
import type { DefineComponent } from 'vue';
import { createApp, h } from 'vue';
import { ZiggyVue } from '../../vendor/tightenco/ziggy';
import { initializeTheme } from './composables/useAppearance';
import { kata, magnet, mulaiGerak, reveal } from './lib/gerak';

declare module 'vite/client' {
    interface ImportMetaEnv {
        readonly VITE_APP_NAME: string;
        [key: string]: string | boolean | undefined;
    }

    interface ImportMeta {
        readonly env: ImportMetaEnv;
        readonly glob: <T>(pattern: string) => Record<string, () => Promise<T>>;
    }
}

const namaApp = import.meta.env.VITE_APP_NAME || 'BoxinGenerated';

createInertiaApp({
    title: (title) => (!title || title.includes(namaApp) ? title || namaApp : `${title} — ${namaApp}`),

    // import.meta.glob memecah tiap halaman jadi berkasnya sendiri, jadi
    // membuka Dasbor tidak ikut mengunduh Rancang dan Riwayat.
    resolve: (name) => resolvePageComponent(`./pages/${name}.vue`, import.meta.glob<DefineComponent>('./pages/**/*.vue')),

    setup({ el, App, props, plugin }) {
        createApp({ render: () => h(App, props) })
            .use(plugin)
            .use(ZiggyVue)
            .directive('reveal', reveal)
            .directive('kata', kata)
            .directive('magnet', magnet)
            .mount(el);
    },

    progress: {
        color: '#8b6dff',
        delay: 120,
    },
});

initializeTheme();
mulaiGerak();
