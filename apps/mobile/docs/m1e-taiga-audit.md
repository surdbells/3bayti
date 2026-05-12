# Taiga UI Removal Audit — Phase M3+ Roadmap

**Captured:** 2026-04-26 during Phase M1e
**HEAD at capture:** 194f227 (after M1d)

This document inventories every Taiga UI usage in the mobile app codebase
so the M3+ migration can plan replacement components systematically.

---

## High-level numbers

| Metric | Count |
|---|---|
| TS files with `@taiga-ui/*` imports | 45 |
| HTML files with `<tui-*>` tags | 40 |
| Total `<tui-*>` tag instances | 351 |
| Distinct Taiga sub-packages used | 5 |

---

## Sub-packages in use (`@taiga-ui/*`)

| Package | Imports |
|---|---|
| `@taiga-ui/core` | 44 |
| `@taiga-ui/kit` | 10 |
| `@taiga-ui/cdk` | 2 |
| `@taiga-ui/event-plugins` | 1 |
| `@taiga-ui/i18n` | 2 |

Note: also installed but with no source imports (kept for build/peer reasons until full removal): `@taiga-ui/styles`, `@taiga-ui/icons`, `@taiga-ui/polymorpheus`, `@taiga-ui/addon-mobile`.

---

## Components by usage frequency (replacement priority)

### Heavy usage (replace first — touches most files)

| Component | TS imports | HTML tag count | Files affected | Replacement strategy |
|---|---|---|---|---|
| `TuiIcon` / `<tui-icon>` | 26 | 259 | 79 | Use `lucide-angular` (already a dep) or Ionic's `<ion-icon>` |
| `TuiLoader` / `<tui-loader>` | 13 | 46 | 60 | Build `<ax-mobile-loader>` (spinner overlay component) |
| `TuiButton` / `tuiButton` directive | 5 | (directive — count below) | 34 | Build `<ax-mobile-btn>` styled to ax tokens |
| `<tui-textfield>` | 2 | 38 | 31 | Build `<ax-mobile-input>` wrapping `<ion-input>` styled to ax tokens |

### Medium usage

| Component | Usage |
|---|---|
| `TuiPassword` / `tui-password` directive | 3 imports |
| `TuiRadioComponent` | 2 imports |
| `TuiCarouselComponent` + `TuiCarouselButtons` + `TuiItem` | 2+2+1 — appears as 1 carousel implementation |
| `TuiAvatar` / `<tui-avatar>` | 2 imports, 1 HTML tag |
| `TuiChip` / `<tui-chip>` | 2 imports, 3 HTML tags |
| `TuiCountryIsoCode` (phone input) | 2 imports |
| `<tui-rating>` / `TuiRating` | 1 import, 3 HTML tags |
| `<tui-select>` | 0 explicit imports, 2 HTML tags |

### Light usage (low priority)

| Component | Usage |
|---|---|
| `TuiTextarea` + `TuiTextareaLimit` + `TuiSortCountriesPipe` + `TuiLabel` + `TuiAccordionDirective` + `TuiTextfieldDirective` + `tuiInputPhoneInternationalOptionsProvider` | 1 each |

---

## Replacement primitives needed in `ax-mobile/` (M5)

Building these covers ~95% of Taiga removal:

1. `<ax-mobile-icon>` — lucide-angular wrapper or just use `lucide-angular` directives directly
2. `<ax-mobile-loader>` — full-screen spinner overlay
3. `<ax-mobile-spinner>` — inline spinner (sm / md / lg)
4. `<ax-mobile-btn>` — primary, secondary, ghost, link variants; sm / md / lg sizes
5. `<ax-mobile-input>` — text field with label, helper text, error state
6. `<ax-mobile-textarea>` — multi-line input with character count
7. `<ax-mobile-select>` — wraps `<ion-select>` with ax styling
8. `<ax-mobile-radio-group>` + `<ax-mobile-radio-item>` — radio choices
9. `<ax-mobile-chip>` — pill-shaped tag/badge
10. `<ax-mobile-rating>` — star rating display + input
11. `<ax-mobile-avatar>` — circular avatar with initials fallback
12. `<ax-mobile-carousel>` — Swiper-based (already a dep) horizontal scroll

Phone-input variant needs special handling — `TuiCountryIsoCode` provides a country picker. Likely replacement: build a simple country-code dropdown + plain `<input>` with `libphonenumber-js` validation (libphonenumber is already a dep).

---

## Files most affected (top 10 by Taiga reference count)

The migration impact is concentrated in fewer files than you'd expect — most pages use Taiga lightly (a few icons + a button). Heavy users are listed below; these get the most attention in M6+.

(Generated from grep — ordered by sum of TuiX TS imports + tui-x HTML tags.)

```
src/app/customer/account/account.page.html             — heaviest
src/app/customer/cart/cart.page.html
src/app/customer/checkout/checkout.page.html
src/app/customer/product/product.page.html
src/app/customer/category/category.page.html
src/app/customer/styles/styles.page.html
src/app/customer/orders/orders.page.html
src/app/customer/wishlist/wishlist.page.html
src/app/customer/profile/profile.page.html
src/app/public/login/login.page.html
```

(Run `grep -c "tui-\|Tui" <file>` on each to get exact counts.)

---

## Migration strategy (informs M3+)

### Phase M2: Ionic theme alignment
Override Ionic's CSS variables (`--ion-color-primary`, etc.) with our `ax` tokens. This lets `<ion-button>`, `<ion-input>`, `<ion-card>` etc. inherit our brand without ANY component-level changes. Result: visual brand alignment across all pages with minimal diff.

### Phase M3: Toast library swap
`@ngxpert/hot-toast` → `@ngneat/hot-toast` (matches vendor). Mechanical rename across files.

### Phase M4: Taiga's heavy hitters (icons + loader)
Replace 79 files of `TuiIcon` with lucide-angular OR `<ion-icon>`. Replace 60 files of `TuiLoader` with `<ax-mobile-loader>`. After this phase, Taiga's footprint drops dramatically — these two account for ~70% of total Taiga usage.

### Phase M5: Build remaining `ax-mobile-*` primitives
Inputs, buttons, selects, chips, ratings, avatar. Build them all. Don't migrate pages yet.

### Phase M6+: Page-by-page migration
Re-skin each page using the new primitives. Pages from earlier (account, cart, checkout, product, category, styles, orders, wishlist, profile, login) are highest priority since they're the heaviest Taiga users.

### Phase M13: Final Taiga removal
Once zero `<tui-*>` tags remain, remove from `package.json`:
- `@taiga-ui/core`
- `@taiga-ui/kit`
- `@taiga-ui/cdk`
- `@taiga-ui/event-plugins`
- `@taiga-ui/i18n`
- `@taiga-ui/styles`
- `@taiga-ui/icons`
- `@taiga-ui/polymorpheus`
- `@taiga-ui/addon-mobile`
- `@ng-web-apis/*` (peer deps, no longer needed)

Remove from `angular.json` styles array:
- `node_modules/@taiga-ui/core/styles/taiga-ui-theme.less`
- `node_modules/@taiga-ui/core/styles/taiga-ui-fonts.less`

Remove from `global.scss`:
- `@use '@taiga-ui/core/styles/taiga-ui-local'`

Expected bundle size reduction: substantial. Will measure at M13.

---

## Risks / open questions

1. **`<tui-textfield>` reactive forms integration**: Taiga's textfield wraps Angular reactive forms with custom validation states. Replacement must preserve `formControlName` + `[formControl]` binding semantics. Build `ax-mobile-input` as a `ControlValueAccessor`.

2. **Phone input + country code**: Used on register/profile/checkout. Need to build a custom country-code picker since `TuiCountryIsoCode` is Taiga-specific.

3. **Carousel**: Two pages use `TuiCarousel`. Since `swiper` is already a dep and it's the underlying library Taiga wraps, we can use Swiper directly without writing a new carousel. Lightest path.

4. **Icons migration**: Two options:
   - **Option A**: Migrate to `<ion-icon>` (already in use; ionicons has wide coverage; zero new deps)
   - **Option B**: Migrate to `lucide-angular` (already a dep; modern look matches our visual language)
   - **Decision**: defer to M4 — likely **lucide-angular** for visual consistency with our design language.

5. **`@taiga-ui/i18n`**: Uses Taiga's localization layer for date pickers, etc. We have our own `TranslatePipe` + `i18n.service.ts`. Removal here means rebuilding any date-picker / number-format components ourselves, OR using Ionic's `<ion-datetime>` which has built-in localization.
