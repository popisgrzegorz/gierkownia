import js from '@eslint/js';
import globals from 'globals';

/**
 * Tylko assets/ - AssetMapper serwuje te pliki wprost przeglądarce (moduły
 * ES, bez bundlera), więc reguły dopasowane pod globalne API przeglądarki
 * i importy z importmap (Stimulus, GSAP), nie pod Node.
 */
export default [
    // assets/vendor/ to Stimulus/Turbo/GSAP wektoryzowane lokalnie przez
    // importmap:install - poza repo (.gitignore) i nie nasz kod do lintowania.
    { ignores: ['assets/vendor/**'] },
    js.configs.recommended,
    {
        files: ['assets/**/*.js'],
        languageOptions: {
            ecmaVersion: 2023,
            sourceType: 'module',
            globals: {
                ...globals.browser,
            },
        },
        rules: {
            // Parametry zdarzeń, których handler nie używa (np. Stimulus
            // action wywoływany bez potrzeby czytania eventu), są normalne.
            'no-unused-vars': ['error', { args: 'none' }],
        },
    },
];
