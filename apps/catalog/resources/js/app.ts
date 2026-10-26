import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initErrorTracking } from '@/lib/errorTracking';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            // Strona „obcego” dostawcy płatności: bez nawigacji marketplace'u.
            case name.startsWith('fake-gateway/'):
                return null;
            // Strona `make errors-smoke`: bez nawigacji, tylko lokalnie (routes/debug.php).
            case name.startsWith('debug/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
    // SDK błędów musi dostać instancję aplikacji Vue (errorHandler); no-op bez DSN.
    withApp: (app) => initErrorTracking(app),
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
