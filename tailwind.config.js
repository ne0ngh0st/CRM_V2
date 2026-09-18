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
                // `font-doc` é só pro documento de orçamento (folha na tela + PDF).
                // Fica separada da interface de propósito: a folha tem que parecer
                // papel, não tela, e o PDF usa exatamente estes mesmos arquivos.
                doc: ['Inter', ...defaultTheme.fontFamily.sans],
            },
            colors: {
                navy: '#0F3A69',
                teal: '#005A6F',
                // `DEFAULT` mantém `text-cyan`/`bg-cyan/10`/`border-cyan` funcionando igual.
                // `dark` é a MESMA cor escurecida, só pra glifo pequeno: o cyan e o âmbar
                // da marca dão ~2,8:1 e ~2,2:1 sobre branco, abaixo dos 3:1 que um ícone
                // fino precisa. Não são cores novas da marca — são tons de apoio.
                cyan: { DEFAULT: '#00A9CE', dark: '#00728B' },
                amber: { DEFAULT: '#ff8f00', dark: '#B36400' },
                // Verde escuro do WhatsApp (#128C7E, cor da própria marca deles). NÃO
                // é cor da Autopel: existe só pro botão de WhatsApp ser reconhecido de
                // relance. O verde vivo da logo (#25D366) dá 1,98:1 sobre branco e
                // sumiria como ícone; este dá 4,14:1.
                whats: '#128C7E',
                // Roxo pastel da INTRANET (Tony, 2026-09-18). Cor de SEÇÃO, não da marca:
                // marca a faixa do Painel e tudo o que é da intranet, do mesmo jeito que o
                // cyan marca o atalho do BI. `DEFAULT` é o pastel (filete, borda, tint via
                // `/10`–`/20`); `dark` é para TEXTO e GLIFO — o pastel puro dá ~2:1 sobre
                // branco e some, o `dark` dá ~7,9:1.
                intranet: { DEFAULT: '#B7A6E8', dark: '#5B3FA0' },
                'brand-gray': '#C8C9C7',
                'corp-black': '#1a1a1a',
                'corp-dark': '#2d2d2d',
            },
        },
    },

    plugins: [forms],
};
