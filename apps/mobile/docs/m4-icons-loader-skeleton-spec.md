# M4 — Icons, Loader, and Skeleton Primitives Spec

**Status:** Locked. Implementation deferred across sub-phases M4a–M4d.
**Captured:** 2026-04-26
**Build phases:** M4a (icons), M4b (icon migration), M4c (loader + skeletons), M4d (loader migration + skeletons in pages)

This document is the authoritative spec for three new ax-mobile primitives:
the `<ax-icon>` wrapper, the `<ax-loader>` spinner, and the
`<ax-skeleton-*>` content placeholders. Together they replace Taiga UI's
`<tui-icon>` and `<tui-loader>` and formalize the hand-rolled skeleton
patterns already scattered across page-level SCSS.

The motivation is the same one that drove M3: the notification surface,
the icons, and the loading indicators are pieces of UI the user sees
constantly. If they don't honor the M2 design system, the app reads as
"premium product wrapped in generic UI primitives" rather than as a
coherent luxury experience.

---

## Why this exists

**Current state:**
- 59 unique `<tui-icon>` names used across 39 page files. Taiga's icon
  set is a re-skin of lucide-react with the prefix `@tui.`.
- `<tui-loader>` used in 30 page files for inline page-loading
  indicators. Generic spinning circle, no brand alignment.
- Hand-rolled skeleton patterns (`.skeleton-shimmer`, `.img-skeleton`,
  `.skeleton-text`) repeated inline across multiple pages with no
  shared API.
- `lucide-angular@0.575.0` already in `package.json`, used in one file
  (`settings.page.ts`).

**Target state:**
- `<ax-icon>` wrapper component using lucide-angular under the hood,
  with M2-aligned size/stroke/color tokens.
- `<ax-loader>` — three-dot pulsing spinner in brand brown, sized
  via tokens.
- `<ax-skeleton-line>`, `<ax-skeleton-block>`, `<ax-skeleton-circle>`
  primitives — shared shimmer animation, neutral palette.
- All `<tui-icon>` and `<tui-loader>` callsites migrated.
- Inline `.skeleton-*` patterns replaced with the primitives in pages
  where it's a low-effort drop-in.

**Why not just keep using Taiga's primitives:**
- M13 removes Taiga UI entirely. M4 is the bridge — replace the parts
  we use now so M13 is a deletion, not a re-implementation.
- Taiga's icon API is locked into Taiga's theme system; our M2 tokens
  don't reach into it.
- Taiga's loader doesn't have a meaningful customization surface and
  reads as generic.

---

## Part 1: `<ax-icon>` (M4a)

### Locked design decisions

- **API:** thin wrapper around `lucide-angular`. `name` is required;
  size/strokeWidth/color are optional with token defaults.
- **Sizes:** five tokens, numeric override allowed.
  | Token | Pixels | Use case |
  |-------|--------|----------|
  | `xs`  | 14     | Inline within text |
  | `sm`  | 16     | Tab labels, form addons |
  | `md`  | 20     | Buttons, list items (default) |
  | `lg`  | 24     | Section headers, cards |
  | `xl`  | 32     | Empty states, hero icons |
- **Color:** inherits from parent via `currentColor`. Caller controls
  via `color: var(--ax-color-text-...)` on the icon's container or
  on the `<ax-icon>` element itself.
- **Stroke width:** default 1.5 (editorial restraint vs lucide's
  default 2). Override via `strokeWidth` input — accepts numeric or
  token (`thin: 1.25, regular: 1.5, bold: 2`).
- **Registration:** eager — all 59 icons registered at app boot via
  a shared `provideAxIcons()` provider in `main.ts`.

### Component API

```typescript
@Component({
  selector: 'ax-icon',
  standalone: true,
  // ...
})
export class AxIconComponent {
  /** Lucide icon name. Required. */
  @Input({ required: true }) name!: string;

  /** Size token or numeric pixel value. Default 'md' (20px). */
  @Input() size: AxIconSize | number = 'md';

  /** Stroke width token or numeric. Default 'regular' (1.5). */
  @Input() strokeWidth: AxIconStrokeWidth | number = 'regular';

  /** Optional CSS color. If omitted, inherits via currentColor. */
  @Input() color?: string;
}

export type AxIconSize = 'xs' | 'sm' | 'md' | 'lg' | 'xl';
export type AxIconStrokeWidth = 'thin' | 'regular' | 'bold';

export const AX_ICON_SIZES: Record<AxIconSize, number> = {
  xs: 14, sm: 16, md: 20, lg: 24, xl: 32,
};

export const AX_ICON_STROKES: Record<AxIconStrokeWidth, number> = {
  thin: 1.25, regular: 1.5, bold: 2,
};
```

### Example usage

```html
<!-- default: 20px, stroke 1.5, currentColor -->
<ax-icon name="arrow-left"></ax-icon>

<!-- size token -->
<ax-icon name="shopping-bag" size="lg"></ax-icon>

<!-- numeric size for one-off cases -->
<ax-icon name="heart" [size]="28"></ax-icon>

<!-- explicit color -->
<ax-icon name="check" color="var(--ax-palette-success-500)"></ax-icon>

<!-- bold stroke for emphasis -->
<ax-icon name="alert-circle" strokeWidth="bold"></ax-icon>

<!-- inheriting color from parent button -->
<button style="color: var(--ax-color-bg-brand)">
  <ax-icon name="plus"></ax-icon>
  Add
</button>
```

### Icon registration

Single provider exported from the icons module, registered once in
`main.ts`:

```typescript
// In main.ts:
import { provideAxIcons } from './app/shared/ax-mobile/icon';

bootstrapApplication(AppComponent, {
  providers: [
    provideAxIcons(),  // registers all 59 icons
    // ...
  ],
});
```

The `provideAxIcons()` function imports each lucide icon symbol and
registers them via lucide-angular's `LucideAngularModule.pick()` /
`provideLucideIcons()` pattern. The exact mechanism follows
lucide-angular's documented registration API — implementation detail
to confirm at M4a build time.

The 59 names are listed in `src/app/shared/ax-mobile/icon/icon-set.ts`.
That file is the single source of truth — adding a new icon means
adding it here, then it's available app-wide.

### Sizing implementation

`<ax-icon>` renders a `<lucide-icon>` internally with size and
strokeWidth resolved from inputs:

```typescript
// Pseudocode for the component template:
<lucide-icon
  [name]="name"
  [size]="resolvedSize"
  [strokeWidth]="resolvedStrokeWidth"
  [style.color]="color || null"
/>

get resolvedSize(): number {
  return typeof this.size === 'number'
    ? this.size
    : AX_ICON_SIZES[this.size];
}
```

### Migration notes (M4b)

The 59 unique `@tui.*` icon names map 1:1 to lucide names because
Taiga's icon set IS lucide. Replacement is `s/@tui.//g` then wrap
in the new component:

```html
<!-- before -->
<tui-icon icon="@tui.arrow-left"></tui-icon>

<!-- after -->
<ax-icon name="arrow-left"></ax-icon>
```

Edge case — one dynamic icon: `<tui-icon icon="@tui.{{category.icon}}">`.
The new component supports `[name]="categoryIconName"` natively. The
migration step strips the `@tui.` prefix from the data source
(`category.icon`) and binds via `[name]`. If category data comes from
a backend with the `@tui.` prefix baked in, the migration adds a
`stripTuiPrefix()` helper or fixes the data at source.

**Confirmed at spec time:** This case appears in `chat.page.html` only.
Data source is `chatService.getPrompts(lang)` → `chat_get_prompts`
backend endpoint, returning `PromptCategory[]` where each has an
`icon: string` field. The backend sends bare lucide names (e.g.
`"shopping-bag"`) — the template currently force-prepends `@tui.`.
Migration is straightforward: change the template to
`<ax-icon [name]="category.icon">` and the existing data flows
through unchanged. No backend or stripping helper needed.

Build script: a Python migration script (similar to M2e/M3b style)
sweeps all 39 HTML files, transforming `<tui-icon icon="@tui.NAME">`
to `<ax-icon name="NAME">`. Spot-check a few files post-migration to
catch edge cases.

### Bundle implications

- Eager registration of 59 icons: roughly 30–40 kB before
  tree-shaking, ~5–10 kB after gzip.
- Removing the Taiga icon assets (`@taiga-ui/icons` copied into
  `www/assets/taiga-ui/icons/` at 3.8 MB) doesn't happen in M4 —
  Taiga is still loaded at runtime for `<tui-loader>` and other
  primitives. Full removal in M13.
- Net impact for M4a: ~+5–10 kB initial gzipped (tradeoff:
  consistency + brand alignment).

---

## Part 2: `<ax-loader>` (M4c)

### Locked design decisions

- **Visual:** three dots pulsing left-to-right (·  ·  ·). Each dot
  cycles opacity 0.3 → 1.0 → 0.3 with a staggered delay so the
  animation reads as a wave rolling through.
- **Color:** `var(--ax-color-bg-brand)` (brown-500). Override via
  `color` input.
- **Sizes:** three tokens.
  | Token | Dot size | Total width | Use case |
  |-------|----------|-------------|----------|
  | `sm`  | 4px      | 32px        | Inline buttons, small spaces |
  | `md`  | 6px      | 48px        | List loading (default) |
  | `lg`  | 8px      | 64px        | Page-level loading |
- **Animation timing:** 1.4s total cycle, dots offset by 0.16s each.
  Matches the cadence of the M3 notification loading dot's pulse.
- **Reduced-motion preference:** dots stop animating, opacity holds
  at 0.7 — visible state, no motion.

### Component API

```typescript
@Component({
  selector: 'ax-loader',
  standalone: true,
  // ...
})
export class AxLoaderComponent {
  /** Size token. Default 'md' (48px wide). */
  @Input() size: AxLoaderSize = 'md';

  /** Optional color override. Defaults to brand brown. */
  @Input() color?: string;

  /** Accessible label for screen readers. Defaults to 'Loading'. */
  @Input() label = 'Loading';
}

export type AxLoaderSize = 'sm' | 'md' | 'lg';
```

### Example usage

```html
<!-- default: medium, brand color -->
<ax-loader></ax-loader>

<!-- small loader, inline in a button -->
<button>
  <ax-loader size="sm"></ax-loader>
  Loading…
</button>

<!-- large page-level loader, custom color -->
<div class="loading-state">
  <ax-loader size="lg" color="var(--ax-color-text-tertiary)"></ax-loader>
</div>
```

### Implementation sketch

```scss
.ax-loader {
  display: inline-flex;
  gap: 4px; /* sm: 2px, md: 4px, lg: 6px — varies by size */
  align-items: center;
}

.ax-loader__dot {
  width: 6px;  /* per size */
  height: 6px;
  border-radius: 50%;
  background: currentColor;
  opacity: 0.3;
  animation: ax-loader-pulse 1.4s ease-in-out infinite;
}

.ax-loader__dot:nth-child(2) { animation-delay: 0.16s; }
.ax-loader__dot:nth-child(3) { animation-delay: 0.32s; }

@keyframes ax-loader-pulse {
  0%, 80%, 100% { opacity: 0.3; transform: scale(0.85); }
  40%           { opacity: 1.0; transform: scale(1); }
}

@media (prefers-reduced-motion: reduce) {
  .ax-loader__dot {
    animation: none;
    opacity: 0.7;
    transform: none;
  }
}
```

### Migration notes (M4d)

Existing `<tui-loader></tui-loader>` becomes `<ax-loader></ax-loader>`.
Sizes default to `md`, which is roughly equivalent to Taiga's loader.
A few callsites might want `lg` for more presence (e.g. full-page
empty-content loaders) — those should be reviewed during M4d, not
blanket-replaced.

Migration script handles the bulk replacement; review lists pages
where `lg` might fit better and tweaks per case.

---

## Part 3: Skeleton primitives (M4c)

### Locked design decisions

- **Three primitives:**
  - `<ax-skeleton-line>` — single horizontal bar for text lines
  - `<ax-skeleton-block>` — rectangular block for image placeholders
  - `<ax-skeleton-circle>` — round shape for avatars
- **Animation:** shimmer sweep (matches existing prior art in
  `best-sellers.page.scss` and friends).
- **Color:** neutral gray gradient — base
  `var(--ax-palette-neutral-200)`, highlight
  `var(--ax-palette-neutral-100)`. Reads clearly as "loading"
  regardless of where placed.
- **No pre-built compositions** in initial spec. Pages compose the
  three primitives into their own layouts.

### Component APIs

```typescript
@Component({
  selector: 'ax-skeleton-line',
  standalone: true,
  template: `<span class="ax-skeleton ax-skeleton--line" [style.width]="width" [style.height.px]="height"></span>`,
})
export class AxSkeletonLineComponent {
  /** Width — CSS value. Default '100%'. */
  @Input() width = '100%';
  /** Height in px. Default 12. */
  @Input() height = 12;
}

@Component({
  selector: 'ax-skeleton-block',
  standalone: true,
  template: `<span class="ax-skeleton ax-skeleton--block" [style.width]="width" [style.height]="height" [style.aspect-ratio]="aspectRatio"></span>`,
})
export class AxSkeletonBlockComponent {
  /** Width — CSS value. Default '100%'. */
  @Input() width = '100%';
  /** Height — CSS value. If aspectRatio also set, height wins. */
  @Input() height?: string;
  /** Optional CSS aspect-ratio (e.g. '3/4' for product cards). */
  @Input() aspectRatio?: string;
}

@Component({
  selector: 'ax-skeleton-circle',
  standalone: true,
  template: `<span class="ax-skeleton ax-skeleton--circle" [style.width.px]="size" [style.height.px]="size"></span>`,
})
export class AxSkeletonCircleComponent {
  /** Diameter in px. Default 40. */
  @Input() size = 40;
}
```

### Shared SCSS

```scss
.ax-skeleton {
  display: inline-block;
  position: relative;
  overflow: hidden;
  background: var(--ax-palette-neutral-200);
  border-radius: var(--ax-radius-sm);

  &::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
      90deg,
      transparent 0%,
      var(--ax-palette-neutral-100) 50%,
      transparent 100%
    );
    animation: ax-skeleton-shimmer 1.6s ease-in-out infinite;
    transform: translateX(-100%);
  }

  &--circle {
    border-radius: 50%;
  }

  &--line {
    border-radius: var(--ax-radius-sm);
  }
}

@keyframes ax-skeleton-shimmer {
  0%   { transform: translateX(-100%); }
  100% { transform: translateX(100%); }
}

@media (prefers-reduced-motion: reduce) {
  .ax-skeleton::after {
    animation: none;
    opacity: 0.5;
  }
}
```

### Example usage

```html
<!-- product card skeleton, composed in a page template -->
<article class="product-card">
  <ax-skeleton-block aspectRatio="3/4"></ax-skeleton-block>
  <div class="info">
    <ax-skeleton-line width="80%" [height]="14"></ax-skeleton-line>
    <ax-skeleton-line width="50%" [height]="12"></ax-skeleton-line>
    <ax-skeleton-line width="40%" [height]="14"></ax-skeleton-line>
  </div>
</article>

<!-- avatar list row skeleton -->
<div class="row">
  <ax-skeleton-circle [size]="48"></ax-skeleton-circle>
  <div class="lines">
    <ax-skeleton-line width="60%"></ax-skeleton-line>
    <ax-skeleton-line width="40%" [height]="10"></ax-skeleton-line>
  </div>
</div>
```

### Migration notes (M4d)

Existing inline skeleton patterns (`.skeleton-shimmer`, `.img-skeleton`,
`.skeleton-text`) are replaced **selectively** in M4d. The audit shows
they exist in `best-sellers.page.html`, `category.page.html`, and a
few others. Each page's skeleton replacement is a small task done
during page-level review.

**Not all pages get skeleton migration in M4d.** Pages with custom
skeleton layouts that the three primitives can't easily compose stay
on their custom CSS. Page-level rewrites in M6+ are the natural
moment to fully unify on the primitives.

The .skeleton-* CSS classes in page-level SCSS files don't need to be
deleted in M4d — they can coexist with the new primitives. M6+ page
migrations clean them up alongside other page-level CSS work.

---

## File layout

```
src/app/shared/ax-mobile/
├── icon/
│   ├── ax-icon.component.ts
│   ├── ax-icon.types.ts
│   ├── icon-set.ts                 # The 59-icon list
│   ├── provide-ax-icons.ts         # Provider function for main.ts
│   └── index.ts
├── loader/
│   ├── ax-loader.component.ts
│   ├── ax-loader.component.scss
│   ├── ax-loader.component.html
│   ├── ax-loader.types.ts
│   └── index.ts
└── skeleton/
    ├── ax-skeleton-line.component.ts
    ├── ax-skeleton-block.component.ts
    ├── ax-skeleton-circle.component.ts
    ├── ax-skeleton.scss            # Shared styles
    └── index.ts
```

Each lives in its own subfolder under `ax-mobile/` for parallel
structure with the existing `notification/` folder from M3a.

---

## Implementation phases

### M4a — Build `<ax-icon>` primitive

1. Create `src/app/shared/ax-mobile/icon/` folder.
2. Implement `AxIconComponent` (template, types, registration helper).
3. Build `icon-set.ts` listing all 59 icon names. Use the audit data
   captured in this spec.
4. Implement `provideAxIcons()` provider function.
5. Wire into `main.ts` providers.
6. Build, verify, commit, push.

**No callsite migration in M4a.** `<tui-icon>` and `<ax-icon>` coexist.

### M4b — Migrate icon callsites

1. Python script: sweep all 39 HTML files, transform
   `<tui-icon icon="@tui.NAME">` → `<ax-icon name="NAME">`.
2. Handle the dynamic icon special case (`{{category.icon}}`).
3. Update each affected component's `imports: [...]` to swap
   `TuiIcon` (or whatever Taiga's icon module exports as) for
   `AxIconComponent`.
4. Build, fix any compile errors (likely missing imports), spot-check
   visually on a few pages.
5. Commit, push.

`<tui-icon>` references are fully gone after M4b. Taiga's icon
module can stay imported at the project level (M13 removes Taiga
proper).

### M4c — Build `<ax-loader>` + skeletons

1. Create `src/app/shared/ax-mobile/loader/` folder.
2. Implement `AxLoaderComponent` (template, SCSS, sizing).
3. Create `src/app/shared/ax-mobile/skeleton/` folder.
4. Implement the three skeleton primitives sharing common
   `ax-skeleton.scss`.
5. Build, verify, commit, push.

**No callsite migration in M4c.**

### M4d — Migrate loader callsites + introduce skeletons

1. Python script: sweep `<tui-loader></tui-loader>` →
   `<ax-loader></ax-loader>` across the 30 page files.
2. Update each affected component's `imports: [...]`.
3. Manual pass: review which pages should bump to `size="lg"`.
4. Optional second pass: replace existing inline skeleton patterns
   in 2–3 pages where the primitives drop in cleanly. (Don't try to
   migrate every page — leave custom-layout skeletons for M6+.)
5. Build, verify, commit, push.

---

## Risk notes

**Lucide-angular version compatibility.** `lucide-angular@0.575.0` is
installed but only used in one file. The provider/registration API in
0.575 may have changed since older versions — confirm at M4a build
time by reading the package's documentation. If the API doesn't match
this spec's pseudocode, adapt the `provideAxIcons()` implementation
to whatever 0.575 actually exposes.

**Icon name drift.** Taiga's `@tui.X` icon names are supposed to be
lucide names, but a small number might be Taiga-specific (icons
present in `@taiga-ui/icons` but not in lucide's main set, or with
slightly different names). The 59 names captured in this spec should
be cross-referenced against lucide's icon catalog at M4a time. Any
mismatches need a name remap or a substitution choice (closest
available lucide icon).

**Skeleton color contrast.** Neutral-200 background with neutral-100
shimmer is a subtle effect. On warm-cream canvas backgrounds (which
M2 made the default), the contrast may be too low. M4c implementation
should test the skeletons against the actual canvas color and bump
the base/highlight values if they're invisible.

**Stroke width 1.5.** Lucide's icons are designed for stroke 2.
Reducing to 1.5 makes them feel lighter — good for the editorial
mood — but at small sizes (xs = 14px), 1.5 may render as 1px due to
sub-pixel rounding, which can look fragile. M4a implementation
should test at all five size tokens. If `xs` looks wrong, the
spec's stroke fallback may need to be size-aware (xs gets stroke 2,
larger sizes stay at 1.5).

**Icon button accessibility.** Icons used inside buttons that have
no other text need an `aria-label`. The `<ax-icon>` doesn't enforce
this — it's the caller's job. Spec callout for documentation, not a
component behavior.

**Loader replacement scope.** Some `<tui-loader>` callsites may be
inside containers with specific sizing assumptions (e.g. centered
in a fixed-height card). Replacing with `<ax-loader>` could shift
visual centering by a few pixels. Spot-check during M4d.

**Skeleton migration vs page rewrites.** M4d does a light pass on
skeletons — only obvious drop-ins. The full skeleton consolidation
happens during M6+ page migrations. This is intentional; trying to
migrate every existing skeleton pattern in M4d would be a rewrite
disguised as a primitive migration.

---

## What this spec deliberately does NOT cover

- **Icon search / lookup tooling.** No "icon picker" component.
- **Animated icons** (e.g. spinning, transitioning between states).
  Ad-hoc; if a future page needs it, build a one-off animation wrapper.
- **Skeleton compositions** (`<ax-skeleton-product-card>` etc.).
  Build later if the same skeleton layout repeats across 3+ pages.
- **Loading state transitions** (cross-fade from skeleton to content
  when data arrives). Out of scope for M4 — the skeleton primitives
  just render until removed by `*ngIf`. Smoother transitions can be
  added later as a host-level pattern.
- **Loader as a global overlay** (full-screen blocking spinner).
  Existing usage is purely inline; if a global overlay is needed, it
  should be a separate primitive (`<ax-loader-overlay>` or similar)
  designed at that point.

---

## Phase M4 commit log (to be filled during implementation)

```
M4a  [pending]  Build ax-icon primitive
M4b  [pending]  Migrate <tui-icon> callsites to <ax-icon>
M4c  [pending]  Build ax-loader + skeleton primitives
M4d  [pending]  Migrate <tui-loader> + introduce skeletons in select pages
```

After M4d ships, Phase M4 is complete. Phase M5 builds remaining
ax-mobile primitives (anything that emerges as needed during page
migrations) and Phase M6+ begins page-by-page migration.
