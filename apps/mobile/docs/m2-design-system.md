# M2 — Mobile Design System

**Captured:** 2026-04-26 at the close of Phase M2
**HEAD at capture:** `8e6fb12` (after M2e)

This document is the authoritative reference for the abayti mobile app's
visual system after the M2 refactor. Future phases (M6+ page migrations,
M14+ performance) should reference this when making styling decisions.

---

## Brand model

The mobile app is a luxury fashion shopping app for the abayti customer.
Design language is intentionally restrained — Net-a-Porter as primary
reference, with editorial cues from Ssense.

The mobile app shares brand tokens with the vendor app (`abayti_vendor`)
but elevates **brown `#906952`** to the visual primary, where vendor
elevates beige `#e8dccb` instead. Both apps read as part of the same
brand family without being identical.

### Color hierarchy

| Role | Token | Value | Usage |
|------|-------|-------|-------|
| Primary brand | `--ax-color-bg-brand` | `#906952` (brown-500) | CTAs only — solid brown buttons |
| Secondary | `--ion-color-secondary` | `#28231f` (espresso-700) | Rare dark accents |
| Tertiary | `--ion-color-tertiary` | `#e8dccb` (beige-300) | Soft brand surfaces (badges, eyebrows) |
| Toolbar | beige-200 (`#efe2cf`) | Subtle brand presence | All `<ion-toolbar>` |
| Canvas | `--ax-color-bg-canvas` | `#faf8f5` (neutral-50) | Default page background |
| Surface | white (`#ffffff`) | Cards, items, inputs |
| Active tab | `--ax-color-text-primary` | `#1c1916` (espresso-800) | Tab active state — calm, not branded |
| Inactive tab | `--ax-color-text-tertiary` | `#7d7669` (neutral-500) | Tab default |

### Status colors

| Role | Token | Value |
|------|-------|-------|
| Success | `--ax-palette-success-500` | `#2e7d40` |
| Warning | `--ax-palette-warning-400` | `#e5a03a` |
| Danger | `--ax-palette-danger-500` | `#a53826` |
| Info | `--ax-palette-info-500` | `#2f5b75` |

Status is deliberately **separate from the brown brand**. A successful
order shows a green check, not a brown one — the brand color and the
status color shouldn't compete.

---

## Typography

Hybrid: Inter body + Poltawski Nowy display. Rationale: Poltawski is a
distinctive display serif we want for product titles and section headers
(brand recognition); Inter is an industry-standard UI sans for body
(readability, especially at smaller sizes and for Arabic).

| Use | Font | Token |
|-----|------|-------|
| Body, UI text, prices, metadata | Inter | `--ax-font-body` |
| Page titles, section heads, product names, toolbar titles | Poltawski Nowy | `--ax-font-display` |
| Microlabels (uppercase eyebrow text) | Inter, 11px, `letter-spacing: 0.08em` | — |

Display type also applies via the global rule:
```scss
h1, h2, h3, h4, h5, h6,
.ax-display,
ion-toolbar ion-title {
  font-family: var(--ax-font-display);
  letter-spacing: var(--ax-ls-tight);
}
```

Both fonts load from Google Fonts via `<link rel="stylesheet">` in
`index.html` (M2a). Preconnect tags reduce latency on first load.

---

## Component patterns (M2c)

### Buttons (`<ion-button>`)

- **Shape:** 12px corners (`--ax-radius-lg`), 48px height
- **Spacing:** 24px horizontal padding (`--ax-space-6`)
- **Type:** Inter 14px / 500 weight / `letter-spacing: 0.04em`
- **No box shadow.** Default text-transform: none.
- **Solid primary:** brown background, white text
- **Outline:** 1px warm-gray border, espresso text
- **Clear:** brand-color text, no background
- **Small variant:** 36px height, 16px horizontal padding, 13px text

### Cards (`<ion-card>`)

- White background
- 12px border-radius
- **No border.** (The previous 2px brown border was removed in M2.)
- Soft warm shadow (`--ax-shadow-sm`)
- 12px outer margin

Card header subtitle: uppercase 12px microlabel via `--ax-color-text-tertiary`.
Card title: Poltawski display, 18px / 600 weight.

### Toolbars (`<ion-toolbar>`)

- Background: beige-200 (`#efe2cf`)
- 56px height (`--ax-header-height`)
- Border: transparent (clean toolbar, no underline)
- Title: Poltawski 18px / 600 weight, tracking-snug

### Tab bar (`<ion-tab-bar>`)

- White background
- 56px height (`--ax-tabbar-height`)
- 1px subtle warm top border
- Inactive label color: warm-gray (`--ax-color-text-tertiary`)
- Active label color: espresso (`--ax-color-text-primary`) + 500 weight bump

### Inputs (`<ion-input>` / `<ion-textarea>`)

- White background, 1px warm-gray border
- 8px corners (`--ax-radius-md`)
- 16px font size (prevents iOS zoom on focus)
- Tertiary placeholder color

### Search (`<ion-searchbar>`)

- White background, 8px corners, no shadow

### List items

- White item background
- Subtle warm separator (`--ax-color-border-subtle`)
- Transparent list background (so items "float" on canvas)

---

## Page chrome

**Removed in M2e:** All hero background images on page chrome.
Pages now use the calm warm canvas (`--ax-color-bg-canvas`) instead of
inline background images.

What this means in practice:
- Home page chrome: warm canvas, products are the visual focus
- Account, search, vendors, vendor-reviews pages: same calm chrome
- Login, register, reset, welcome pages: no decorative chrome
  (still have `<img>` content like logos and welcome imagery)

What remains as content imagery:
- `logo.png` — used as `<img>` on 5 pages, not chrome
- Intro slide-1.jpg / slide-2.jpg / slide-3.jpg — onboarding content
- Product imagery, vendor cover images, etc. — content, not chrome

---

## What lives where

### `src/app/shared/ax-mobile/_tokens.scss` (M1d)
Single source of truth for all visual properties. Palette, type, spacing,
radii, shadows, motion, status colors, breakpoints. Mobile-tuned values
that align with the vendor app's `ax-*` system.

### `src/theme/variables.scss` (M2b)
Maps Ionic's CSS variables to ax-* tokens. Every `<ion-*>` component
inherits the brand without per-component changes.

Also includes:
- App-specific shorthand vars (`--text`, `--muted`, `--bg`, `--card`,
  `--ink`, `--profile-bg`, `--header-bg`, `--border`, `--shadow-sm`,
  `--shadow-md`, `--radius-lg/xl/2xl`) retargeted to ax tokens for
  pages that haven't been migrated yet.

### `src/global.scss`
- M1d: imports `_tokens.scss`
- M2a: font setup (body Inter, display headings)
- M2c: component pattern overrides
- M2d: app-wide utility classes (`.abayti_*`, `.search-wrap`,
  `.custom-list`, `.back-chip`, `.abayti_border`, `.remove_nudge`)
  retargeted to ax tokens, hard-coded colors removed
- M2e: `ion-content.page-bg` rule retargeted to canvas

### `src/index.html` (M2a, M2e)
Loads Inter and Poltawski Nowy from Google Fonts with preconnect.
Preloads `logo.png` only (other image preloads removed in M2e).

---

## Bundle delta (M1a baseline → M2 final)

Both measurements are production builds.

| Metric | M1a baseline | M2 final | Delta |
|---|---|---|---|
| Initial JS+CSS gzipped | 124 kB | 125 kB | +1 kB |
| Initial JS+CSS raw | 722 kB | 733 kB | +11 kB |
| Largest single chunk (gz) | 51 kB | 51 kB | 0 |
| Lazy chunks | 191 | 201 | +10 |
| Total www/ size | 15 MB | 22 MB | +7 MB |

**Reading these numbers:**

- **+1 kB gzipped initial** is essentially flat. The whole M2 design
  system ships in roughly the same byte budget as the original. This is
  the right outcome — design refactoring shouldn't bloat the runtime.
- **+10 lazy chunks** comes from new component-level Ionic primitives
  the build now references (e.g., the override rules introduced selectors
  the build didn't see before).
- **+7 MB www total** is the build pipeline copying `@taiga-ui/icons`
  assets that weren't being copied at the M1a baseline. Not a real
  shipped-to-user growth — those don't enter the JS bundle. Will be
  cleaned in M13 when Taiga UI is removed entirely.

Initial bundle remains well within budget. Production-bundle size is
not the priority for M2; visual consistency is.

---

## Migration debt — what's NOT in M2

These are intentionally deferred:

1. **Page-level SCSS files** still contain their own hard-coded brand
   colors (e.g., `account.page.scss`, `home.page.scss`). M2 was scoped
   to global treatment. Per-page migration happens in M6+.

2. **Taiga UI components** still themed with their own styles. The
   `<tui-*>` selectors don't pick up `--ion-color-*` overrides. Taiga
   removal is M13.

3. **The `.abayti_*` utility classes** were retargeted (not deleted)
   despite some having zero call sites. A dead-code purge can happen
   in a separate phase.

4. **Asset files** in `src/assets/images/` (welcome.png, home_bg.png,
   etc.) are no longer referenced as backgrounds. They could be deleted
   to save ~5 MB of install weight, but only after auditing every
   `<img>` and dynamic asset reference. Out of scope for M2.

5. **Per-page background image opt-in.** If a page wants a hero image
   in the future, it should use a real `<img>` element or a dedicated
   `.hero` SCSS rule, NOT bring back the `--background: url(...)` pattern.

---

## Quick reference — token recipes

When writing new component CSS, use these patterns:

```scss
.my-card {
  background: var(--ax-palette-neutral-0);
  border: none;
  border-radius: var(--ax-radius-lg);
  box-shadow: var(--ax-shadow-sm);
  padding: var(--ax-space-4);
}

.my-cta {
  background: var(--ax-color-bg-brand);
  color: var(--ax-color-text-on-brand);
  border-radius: var(--ax-radius-lg);
  padding: var(--ax-space-3) var(--ax-space-6);
  font-family: var(--ax-font-body);
  font-weight: var(--ax-fw-medium);
  letter-spacing: var(--ax-ls-wide);
}

.my-title {
  font-family: var(--ax-font-display);
  font-size: var(--ax-fs-2xl);
  font-weight: var(--ax-fw-semibold);
  letter-spacing: var(--ax-ls-snug);
  color: var(--ax-color-text-primary);
}

.my-eyebrow {
  font-family: var(--ax-font-body);
  font-size: var(--ax-fs-2xs);
  font-weight: var(--ax-fw-medium);
  letter-spacing: var(--ax-ls-wider);
  text-transform: uppercase;
  color: var(--ax-color-text-tertiary);
}
```

Avoid:
- Hard-coded hex values in component SCSS (use tokens instead)
- Inline brand colors in HTML attributes (`style="color: #906952"`)
- Using `--ax-palette-*` directly when a `--ax-color-*` semantic alias exists
- New `--background: url(...)` rules (use `<img>` content elements instead)
- Re-introducing `font-family: 'Poltawski Nowy' !important` rules
  (the global rule was removed for a reason; selective application via
  `.ax-display` or heading tags is the pattern)

---

## Phase M2 commit log

```
01ebe93  M2a: font infrastructure — Inter body + Poltawski Nowy display
8f0f5aa  M2b: override Ionic theme to use ax-* design tokens
fc719c8  M2c: component pattern overrides for Ionic primitives
da5f713  M2d: strip hard-coded brand colors from global.scss
8e6fb12  M2e: remove background images from page chrome
[this commit]  M2f: design system doc + bundle delta capture
```

After M2f, Phase M2 is complete. Phase M3 starts with the toast library
swap (`@ngxpert/hot-toast` → `@ngneat/hot-toast` to match vendor).
