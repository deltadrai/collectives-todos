/** @type {import('tailwindcss').Config} */
module.exports = {
    content: ['./index.html', './privacy.html', './js/*.js'],
    theme: {
        extend: {
            colors: {
                nc: {
                    blue: '#0082c9',
                    'blue-dark': '#00639a',
                    'blue-deep': '#004a70',
                    bg: '#f0f4f8',
                    card: '#ffffff',
                    text: '#222d32',
                    muted: '#6b7a85',
                    line: '#e3e9ee',
                },
            },
            fontFamily: {
                sans: ['-apple-system', 'BlinkMacSystemFont', "'Segoe UI'", 'Roboto', "'Helvetica Neue'", 'Arial', 'sans-serif'],
            },
        },
    },
};
