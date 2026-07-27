import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.vue',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: '#0F3A69',
                teal: '#005A6F',
                cyan: '#00A9CE',
                amber: '#ff8f00',
                'brand-gray': '#C8C9C7',
                'corp-black': '#1a1a1a',
                'corp-dark': '#2d2d2d',
            },
        },
    },

    plugins: [forms],
};
