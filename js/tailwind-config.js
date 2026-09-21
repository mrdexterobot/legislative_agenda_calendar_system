// Shared Tailwind (Play CDN) configuration.
// Loaded right after the CDN script on every page, so all pages share
// one token set. Hex values mirror css/styles.css custom properties —
// duplicated here because the Play CDN can't read CSS variables at
// class-generation time.
tailwind.config = {
  theme: {
    extend: {
      colors: {
        ink: {
          900: '#16243D',
          800: '#1D2F4E',
          700: '#2C4570',
          600: '#3C5A8C',
        },
        brass: {
          700: '#8A6308',
          600: '#A8790C',
          100: '#F1E6C4',
        },
        maroon: {
          700: '#7A2331',
          600: '#93303F',
          100: '#F2DEE1',
        },
        forest: {
          700: '#2F6F4E',
          600: '#3B8560',
          100: '#DCEEE3',
        },
        info: {
          700: '#1F4E8C',
          600: '#33639F',
          100: '#DCE7F5',
        },
        paper: {
          50: '#F1EDE0',
          100: '#E8E2CE',
        },
      },
      fontFamily: {
        display: ['"Source Serif 4"', 'Georgia', 'serif'],
        sans: ['"IBM Plex Sans"', 'ui-sans-serif', 'system-ui', 'sans-serif'],
        mono: ['"IBM Plex Mono"', 'ui-monospace', 'monospace'],
      },
    },
  },
};
