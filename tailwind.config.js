import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
    ],

    theme: {
        extend: {
            fontFamily: {
                sans: ['Figtree', ...defaultTheme.fontFamily.sans],
                heading: ['Poppins', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                // Tren Maya brand palette, sampled from public/logo.png.
                brand: {
                    mist: '#F2F7F5', // page background tint
                    mint: '#70C2AD', // logo icon / tagline mint — decorative & large text only (2.1:1 on white)
                    teal: '#1F7460', // accessible teal for links/focus/borders (5.64:1 on white)
                    green: '#06534D', // wordmark dark green — primary actions/text (8.93:1 on white)
                    'green-dark': '#043D39', // hover/active state for primary green
                },
            },
        },
    },

    plugins: [forms],
};
