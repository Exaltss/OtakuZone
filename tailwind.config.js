/** @type {import('tailwindcss').Config} */
export default {
  content: [
    './resources/views/**/*.blade.php',
    './resources/js/**/*.tsx',
    './resources/js/**/*.ts',
  ],
  theme: {
    extend: {
      colors: {
        night: {
          950: '#04060b',
          900: '#0a0f1c',
          850: '#0d1524',
          800: '#111b2e',
          700: '#1a2740',
        },
        accent: {
          DEFAULT: '#f9a03f',
          soft: '#fbb054',
          deep: '#ea8c26',
        },
      },
      fontFamily: {
        sans: [
          'Plus Jakarta Sans',
          'ui-sans-serif',
          'system-ui',
          '-apple-system',
          'Segoe UI',
          'Roboto',
          'sans-serif',
        ],
      },
      boxShadow: {
        card: '0 10px 30px -12px rgba(0,0,0,0.65)',
      },
    },
  },
  plugins: [],
}
