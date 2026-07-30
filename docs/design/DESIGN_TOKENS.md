# Suvakamana — Design Tokens

Source of truth for Phase 1 UI. Phase 0 ships the CSS variable file so the frontend boots with brand colours.

## Brand personality

Premium · Warm · Modern · Trustworthy · Elegant · Clean

## Colour tokens

```css
:root {
  /* Primary */
  --color-charcoal: #161614;
  --color-warm-black: #0d0d0c;
  --color-soft-cream: #f7f1e7;
  --color-porcelain: #fffdf8;

  /* Accents */
  --color-burnt-orange: #d8662d;
  --color-copper: #b87333;
  --color-warm-gold: #d6a64f;
  --color-olive: #66754a;

  /* Semantic */
  --color-success: #2f6b4f;
  --color-warning: #c4922a;
  --color-error: #a33b2d;
  --color-info: #4a6d8c;

  /* Surfaces */
  --surface-page: var(--color-porcelain);
  --surface-elevated: #ffffff;
  --surface-muted: var(--color-soft-cream);
  --surface-inverse: var(--color-warm-black);
  --surface-glass: rgba(255, 253, 248, 0.72);

  /* Text */
  --text-primary: var(--color-charcoal);
  --text-secondary: #5c574e;
  --text-muted: #8a8478;
  --text-inverse: var(--color-porcelain);
  --text-accent: var(--color-burnt-orange);

  /* Borders */
  --border-subtle: rgba(22, 22, 20, 0.08);
  --border-strong: rgba(22, 22, 20, 0.16);
  --border-accent: rgba(184, 115, 51, 0.45);

  /* Shadows */
  --shadow-sm: 0 1px 2px rgba(13, 13, 12, 0.06);
  --shadow-md: 0 8px 24px rgba(13, 13, 12, 0.08);
  --shadow-lg: 0 20px 48px rgba(13, 13, 12, 0.12);
  --shadow-glow-copper: 0 8px 28px rgba(216, 102, 45, 0.22);

  /* Radii */
  --radius-sm: 0.375rem;
  --radius-md: 0.75rem;
  --radius-lg: 1rem;
  --radius-xl: 1.5rem;
  --radius-pill: 9999px;

  /* Motion */
  --ease-out-premium: cubic-bezier(0.22, 1, 0.36, 1);
  --duration-fast: 150ms;
  --duration-base: 250ms;
  --duration-slow: 400ms;
}
```

## Typography

| Role | Family | Fallback |
|------|--------|----------|
| Display / headings | DM Serif Display | Georgia, serif |
| Body / UI | Plus Jakarta Sans | system-ui, sans-serif |

```css
:root {
  --font-display: "DM Serif Display", Georgia, serif;
  --font-body: "Plus Jakarta Sans", system-ui, sans-serif;

  --text-xs: 0.75rem;
  --text-sm: 0.875rem;
  --text-base: 1rem;
  --text-lg: 1.125rem;
  --text-xl: 1.25rem;
  --text-2xl: 1.5rem;
  --text-3xl: 1.875rem;
  --text-4xl: 2.25rem;
  --text-5xl: 3rem;
  --text-hero: clamp(2.5rem, 5vw, 4rem);
}
```

## Spacing scale

```text
4 · 8 · 12 · 16 · 24 · 32 · 48 · 64 · 96
```

## Component defaults (Phase 1)

| Component | Token usage |
|-----------|-------------|
| Primary button | `--color-burnt-orange` bg, white text, `--radius-md`, `--shadow-glow-copper` |
| Secondary button | transparent / cream, `--border-strong` |
| Cards | large image, `--shadow-md`, `--radius-lg`, hover image scale |
| Admin sidebar | `--color-warm-black` |
| Admin content | `--color-soft-cream` |
| Glass panels | `--surface-glass` + backdrop-blur |

## Accessibility

- Body text on cream/porcelain: charcoal (`#161614`) — contrast ≥ 4.5:1
- Primary button white on burnt orange — verify ≥ 4.5:1
- Never use gold/copper text on cream for small body copy

## File mapping

| File | Purpose |
|------|---------|
| `frontend/src/styles/tokens.css` | CSS variables (Phase 0) |
| `frontend/tailwind.config.ts` | Tailwind theme extension (Phase 0/1) |
| `docs/design/DESIGN_TOKENS.md` | This document |
