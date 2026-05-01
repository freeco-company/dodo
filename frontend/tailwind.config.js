/** @type {import('tailwindcss').Config} */
// Pandora Meal frontend Tailwind config — replaces cdn.tailwindcss.com
// (Apple App Store policy: no remote JS execution / dynamic style loading).
//
// Build:  npm run build:tailwind  (outputs ./public/tailwind.css)
// Dev:    npx tailwindcss -i src/tailwind-input.css -o public/tailwind.css --watch
module.exports = {
  content: [
    './public/**/*.html',
    './public/**/*.js',
    // dev-only smoke page lives outside public/, intentionally not scanned.
  ],
  theme: {
    extend: {
      colors: {
        // Brand tokens (pull from style.css :root vars eventually). Kept minimal
        // so most styling continues to come from style.css; tailwind covers
        // utility classes used inline in index.html (max-w-md mx-auto, etc.).
        brand: {
          peach: '#F4C5A3',
        },
      },
    },
  },
  plugins: [],
};
