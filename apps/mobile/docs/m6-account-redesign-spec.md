# M6 — Account Page Visual Redesign Spec

**Status:** Locked. Implementation deferred across sub-phases M6c–M6f.
**Captured:** 2026-04-26
**Build phases:** M6c (header + search + categories), M6d (product carousels + section headers), M6e (vendor featured + popular stores + empty state + Taiga primitive replacements), M6f (tab bar + wishlist modal + polish).

This document is the authoritative spec for the visual redesign of
`account.page` — the customer-facing home/discovery surface — using
the M2 design system. Despite the route name, this is *the* primary
landing page of the app, where users browse products, see carousels,
search, and access their cart/wishlist/messages. It is the single page
most users see most often.

The redesign moves account.page from its current deep-red/peach palette
(a holdover from before M2 locked in brown #906952 as the mobile
primary) to the M2 brown + beige + gold + neutrals system, with
editorial typography, calmer rhythm, and the card/spacing/shadow
treatments already locked in M2.

The motivation is direct: M2 / M3 / M4 / M6a / M6b have been
structural refactors invisible to users. account.page is where the
M2 design system finally becomes visible product. If users don't see
the new design here, they don't see it anywhere.

---

## Why this exists

**Current state:**
- account.page uses a deep-red and peach palette: header `#FBEDE2`,
  accent `#7C2108`, "show all" link `#B92800`, footer tab bar
  `#7C2108`. None of these colors appear in the M2 token set.
- Section headers use bold sans-serif h2 with red color.
- Product cards have heavy red `#7A1C09` background fills behind images.
- Header avatar uses `<tui-avatar>`, search uses `<tui-textfield>`,
  category strip uses inline `.chip` buttons. Tab bar uses inline
  styles with deep red.
- Wishlist modal mixes Ionic and Taiga primitives.
- Empty state uses `<tui-icon>` (already migrated) but no consistent
  empty-state treatment.

**Target state:**
- Beige `#efe2cf` header, brown `#906952` brand accents, gold `#c9a227`
  rating star, neutrals `#1c1916` text / `#7d7669` muted / `#a8a092`
  faintest.
- Section headers use Poltawski Nowy regular weight, 22px, color
  `#1c1916`.
- Product cards have no card chrome — image area + caption only,
  warm neutral image placeholder.
- Header uses Ionic-native `<ion-avatar>` (replacing `<tui-avatar>`),
  search uses `<ion-input>` styled per M2c (replacing `<tui-textfield>`),
  category chips use plain HTML buttons styled with M2 tokens.
- White tab bar with 0.5px top border, brown active state with
  2px brown underline.
- Wishlist modal uses Ionic primitives consistently.
- Empty state uses M2 spacing/typography conventions.

**Taiga primitives absorbed by this redesign:**
- `<tui-avatar>` → `<ion-avatar>` (header user avatar)
- `<tui-textfield>` → `<ion-input>` (search bar — Path Y, M2c styling already in place)
- `<tui-chip>` → not used in account.page after redesign (categories
  become plain styled buttons, not chips)
- `tuiFallbackSrc` pipe → conditional `[src]` binding with default avatar asset
- `TuiShimmer` → `<ax-skeleton-block>` from M4 (already exists)
- `TuiSelectDirective`, `TuiRadioComponent`, `TuiDataListWrapperComponent`,
  `TuiTextfieldDropdownDirective`, `TuiTextfieldOptionsDirective`,
  `TuiChevron`, `TuiLabel` — all confirmed unused in current
  account.page after audit. Will be removed from the imports during
  M6e.

After this redesign, the only Taiga primitive remaining anywhere
in account.page is none — the page is fully off Taiga.

---

## Color tokens used by this redesign

All colors come from the M2 token set. None are introduced for this
redesign. If implementation needs a color not listed here, that's a
signal to either extend M2 tokens (in a separate commit) or use an
existing token closer in spirit.

| Role | Token | Value |
|------|-------|-------|
| Header background | `--ax-color-bg-header` | `#efe2cf` |
| Brand accent / primary | `--ax-color-bg-brand` | `#906952` |
| Brand text accent | `--ax-color-text-brand` | `#614536` |
| Body text | `--ax-color-text-primary` | `#1c1916` |
| Muted text | `--ax-color-text-secondary` | `#7d7669` |
| Faint text / inactive | `--ax-color-text-tertiary` | `#a8a092` |
| Surface (cards) | `--ax-color-bg-surface` | `#ffffff` |
| Subtle border | `--ax-color-border-default` | `#d1c8bb` |
| Faint divider | `--ax-color-border-subtle` | `#e4ddd3` |
| Image placeholder | `--ax-color-bg-placeholder` | `#ead9ca` |
| Rating star (gold) | `--ax-color-accent-gold` | `#c9a227` |
| Overlay / badge bg | `--ax-color-bg-overlay` | `rgba(28,25,22,0.7)` |

Implementation note: M2c already defines most of these tokens. M6
implementation should verify each is present in `src/global.scss`
before referencing; add any missing in a one-liner pre-pass commit.

---

## Typography

| Role | Font | Size | Weight | Color |
|------|------|------|--------|-------|
| Section headers (Best sellers, New arrivals, Popular stores) | Poltawski Nowy | 22px | 400 (regular) | `#1c1916` |
| Vendor name (in featured card hero overlay) | Poltawski Nowy | 18px | 400 (regular) | `#ffffff` |
| Body / product names | Inter | 13px | 400 (regular) | `#1c1916` |
| Price | Inter | 13px | 500 (medium) | `#1c1916` |
| Welcome strip — "Welcome back" | Inter | 12px | 400 | `#5a554a` |
| Welcome strip — user name | Inter | 14px | 500 | `#1c1916` |
| "SHOW ALL →" link | Inter | 12px | 400 | `#614536`, letter-spacing 0.04em, uppercase |
| "FEATURED COLLECTION" label | Inter | 11px | 400 | `#5a554a`, letter-spacing 0.06em, uppercase |
| Tab bar labels | Inter | 10px | 500 active / 400 inactive | brown active / muted inactive |
| Vendor badge on product card | Inter | 10px | 400 | `#ffffff` on overlay |

Poltawski Nowy is already loaded by M2 via `@font-face` in
`src/global.scss`. Implementation references it via
`var(--ax-font-display)` token. **Use regular weight only** — the
italic weight reads too magazine-y for this app.

---

## Layout: section by section

### Header (top of page)

Replaces the current `<ion-header>` block.

**Structure:**

```
<ion-header [no border]>
  <ion-toolbar [transparent, beige bg from page]>
    <welcome strip>
      <avatar 40×40 round> + <welcome text> + <action icons>
    </welcome strip>
    <search bar>
  </ion-toolbar>
  <category strip>
</ion-header>
```

**Welcome strip:**
- Avatar: 40×40 round, fallback to brown initials on `#d5b49e` background
- Welcome text stacked: "Welcome back" small muted on top, user
  first name medium-weight body color underneath
- Action icons: heart, message, cart — 18px lucide stroke 1.5,
  color `#1c1916`, gap 14px between them

**Search bar:**
- White background, 0.5px border `#d1c8bb`, 12px radius, 10px×14px padding
- Search icon (lucide) 16px stroke 1.5 in `#7d7669` on the left
- Placeholder text 13px Inter in `#7d7669`
- Click triggers existing `user_search()` handler

**Category strip:**
- Horizontal scrolling row of pill buttons, 8px gap
- Each chip: white background, 0.5px border `#d1c8bb`, 999px radius,
  6px×14px padding, 12px Inter text, color `#1c1916`
- No icons inside chips (current design has icons; redesign drops them
  for cleaner minimalism)
- Clicking each chip calls `open_category(id, name)` as today

The whole header block sits on a continuous beige `#efe2cf`
background, with subtle 0.5px border at its bottom edge separating
it from the white content area below.

---

### Section header pattern (Best sellers, New arrivals, Popular stores)

Used wherever a horizontal carousel of products/stores appears.

**Structure:**

```
<row, baseline-aligned>
  <h2 Poltawski regular 22px>{section title}</h2>
  <a SHOW ALL →>
</row>
<horizontal scroll row of items>
```

**Show all link:**
- Style: 12px Inter, color `#614536`, uppercase, 0.04em letter-spacing
- Trailing arrow: `→` character or lucide `arrow-right` icon at 12px
- No underline; the contrast comes from spacing and the uppercase tracking
- Removes the current `style="color: #B92800"` red treatment entirely

---

### Product card (used in Best sellers, New arrivals)

This is the primary card type on the page. The redesign strips the
current chrome (red background, heavy borders) and lets the image
do the work.

**Structure:**

```
<article width 158px>
  <div aspect-ratio 3/4 image area>
    <wishlist heart-plus button>
    <vendor name badge>
    <img>
  </div>
  <div product name>
  <div price>
</article>
```

**Image area:**
- aspect-ratio 3/4 (taller than wide — fashion product proportions)
- 8px radius, no border
- Background `#ead9ca` while image loads (warm neutral, replaces
  current `#7A1C09` red)
- Skeleton from M4 `<ax-skeleton-block>` slots in here during loading

**Wishlist button (top-right of image):**
- 30px round, white-translucent (`rgba(255,255,255,0.85)`) background, no border
- 14px lucide `heart-plus` icon, stroke 1.5, color `#614536`
- 8px from top and right edges
- Clicking triggers existing `add_to_wishlist(product)` flow

**Vendor name badge (bottom-left of image):**
- 3px×8px padding, 4px radius
- Background `rgba(28,25,22,0.7)` overlay
- 10px Inter regular white text
- 8px from left and bottom edges
- Clicking opens vendor (existing `open_vendor()` handler)

**Caption (below image, no surrounding card):**
- 8px gap from image
- Product name: 13px Inter regular, color `#1c1916`,
  line-height 1.4, max 2 lines (text truncates with ellipsis)
- Price: 13px Inter medium, color `#1c1916`, 4px above name
- No "from $X" prefix needed — currency comes through the existing
  data binding

The horizontal carousel keeps 12px gap between cards and sits
flush with the page padding (no extra inset).

---

### Vendor featured card

A larger card type that appears once on the page, highlighting one
featured vendor with hero image, rating, and a 3-product preview row.

**Structure:**

```
<article white, 12px radius, soft shadow>
  <hero image area aspect 16/10>
    <gradient overlay bottom>
    <store name + rating overlay>
  </hero>
  <body padding>
    <FEATURED COLLECTION label>
    <3-square preview row>
  </body>
</article>
```

**Hero image:**
- aspect-ratio 16/10
- Background `#d5b49e` while loading (warm, slightly darker than
  product card placeholder for visual hierarchy)
- Bottom gradient overlay: `linear-gradient(180deg, transparent 50%,
  rgba(28,25,22,0.55) 100%)` — fades from transparent at midline to
  dark at bottom, so text reads on busy images

**Hero text overlay (positioned bottom-left of hero):**
- Store name: 18px Poltawski Nowy regular, color white, letter-spacing
  -0.005em, line-height 1.2
- Rating row 3px below: gold star (lucide, 12px) + "{rating} · {n}
  reviews" in 11px Inter at 0.85 opacity white

**FEATURED COLLECTION label (in body, above preview row):**
- 11px Inter, color `#5a554a`, 0.06em letter-spacing, uppercase
- 12px from top of body, 10px above preview row

**3-square preview row:**
- 3 columns equal width, 8px gap between
- Each: aspect-ratio 1, 4px radius, warm neutral placeholder
  (varied tones: `#ead9ca`, `#d5b49e`, `#b68e75` for visual interest)
- Each square is clickable and opens the corresponding product

**Card shadow:**
- `box-shadow: 0 1px 3px rgba(46,36,28,0.06)` — extremely subtle,
  barely there. Replaces M2's "soft shadow" token if it exists.

---

### Popular stores section

Same section header pattern as Best sellers / New arrivals.
Items are simpler — each is a small store card.

**Each store item:**
- 80px round image (store logo), warm placeholder background
- Store name below, 13px Inter regular, max 2 lines, centered
- 12px gap between items in horizontal scroll

---

### Empty state branch

Shown when `ui_controls.is_empty` is true (no content available).

- Single illustration or large brand icon (subtle, brown stroke, 48px)
- Heading: 18px Poltawski Nowy regular `#1c1916`, "Nothing to show yet"
  (or the existing translation key)
- Subtext: 13px Inter regular `#7d7669` muted, single line
- Single brown `<ion-button shape="round">` with action label
  (matches M6a Pattern B styling)
- Centered vertically in the available content space, ~30% from top

---

### Wishlist modal

Modal sheet that slides up to let users add a new wishlist label.

- Use Ionic-native `<ion-modal>` with `initialBreakpoint="0.5"`
  (already in place; redesign keeps the breakpoint behavior)
- Modal sheet has 12px top corners, white background, 0.5px top
  border `#e4ddd3`
- Header: small handle bar 36×4px `#d1c8bb` centered above title
- Title: 18px Poltawski Nowy regular, color `#1c1916`, centered
- List of existing wishlist labels: each is a row with 12px Inter
  regular text + small lucide chevron-right icon, divider 0.5px
  `#e4ddd3` between rows
- Bottom: brown `<ion-button expand="block">` "Add to closet" matching
  M6a Pattern A
- All Taiga primitives in the current modal (checkboxes, etc.)
  replaced with Ionic equivalents

---

### Tab bar / footer

The single biggest visual change in the redesign. Replaces the deep
red `#7C2108` footer with M2-aligned white.

**Structure:**

```
<ion-footer [white, 0.5px top border]>
  <ion-tab-bar>
    Home / Explore / Cart / Account (4 tabs)
  </ion-tab-bar>
</ion-footer>
```

**Bar styling:**
- Background white `#ffffff`
- 0.5px top border `#e4ddd3` (subtle separator from content)
- 10px×8px×12px padding
- No shadows, no dropshadow effects

**Each tab:**
- 22px lucide icon, stroke 1.5
- 10px Inter label, 4px gap below icon
- Active state: icon + label both `#906952` brown, label
  weight 500, plus 2px×24px brown underline 10px below the label
  (at the bottom edge of the tab button)
- Inactive state: icon + label both `#a8a092` faint grey, label
  weight 400, no underline
- 4px×6px padding around tap target (44px minimum tap target via
  the surrounding touch padding)

**Cart icon:**
- Replaces the existing `<app-cart-icon>` styling but keeps the
  cart-with-badge component intact — the badge count overlay still
  shows when items are in cart
- Badge color: brown `#906952` instead of current red

---

## Implementation phasing

This redesign cannot be done in one session. Account.page is 1300+
lines across HTML/TS/SCSS, and the redesign touches every section.
Phasing into 4 sub-phases keeps each session focused and each commit
bisectable.

### M6c — Header + search + categories

- Replace `<tui-avatar>` with `<ion-avatar>`
- Replace `<tui-textfield>` search with M2c-styled `<ion-input>`
- Restyle category strip (drop icons, white pills with border)
- Update header SCSS to use new beige + brown palette
- Replace `tuiFallbackSrc` pipe with conditional binding
- Build green, deploy to verify the top of the page shows the new
  design before touching the rest

### M6d — Section headers + product cards

- Update `.product-card` SCSS: drop red backgrounds, add new caption
  layout, restyle wishlist button + vendor badge
- Update section header SCSS: Poltawski regular 22px, brown SHOW ALL
- Apply to both Best Sellers and New Arrivals sections
- Build green, verify carousels render correctly

### M6e — Vendor featured + popular stores + empty state + Taiga cleanup

- Build the redesigned vendor featured card
- Restyle popular stores section
- Apply M2 empty state treatment
- Remove all remaining Taiga imports from `account.page.ts` that
  are now dead (`TuiShimmer`, `TuiAvatar`, `TuiSelectDirective`,
  `TuiRadioComponent`, etc.)
- Build green

### M6f — Tab bar + wishlist modal + polish

- Replace the deep-red `<ion-footer>` styling with white + brown active
- Restyle the wishlist modal
- Polish pass: spacing, alignment, typography consistency check
- Final Taiga import audit on account.page (should be zero by now)
- Build green, deploy, verify the whole page

Each phase ends with build + commit + push. If any phase surfaces a
design question that wasn't fully captured in this spec, it gets
resolved in-session and the spec is amended.

---

## Out of scope for M6

The following are explicitly NOT part of this redesign:

- Other pages. Only account.page changes. Other pages keep their
  current Taiga + deep-red mix until their own M6+ phase.
- Mobile vs tablet layout. Current responsive behavior preserved.
- New features. No new buttons, sections, or behaviors. Pure visual
  refresh of existing functionality.
- Backend changes. No API or data shape changes.
- Dark mode. The app currently has no dark mode and adding one is
  separate work.
- Animation / transition redesign. Existing `animate__animated`
  classes preserved on form buttons; no new motion design.
- Accessibility audit beyond preserving existing aria attributes
  and ensuring color contrast remains AA on text.

---

## Locked decisions log

For each decision below, the alternatives I considered and why this
one won. Useful when the design is questioned later or when applying
the same logic to other pages' redesigns.

**Beige `#efe2cf` header (not white, not brown):**
- White rejected: too clinical, loses the warmth that signals luxury fashion.
- Brown rejected: too heavy at the top of every screen.
- Beige carries the M2 brand warmth while keeping the header light.

**Poltawski Nowy regular weight (not italic, not bold):**
- Italic rejected by user: too magazine-y, leans Vogue.
- Bold rejected: heavy, fights with the editorial mood.
- Regular weight at 22px gives serif elegance without ornamentation.

**Product card with no chrome (vs. white card with shadow):**
- White card considered: would match the vendor featured card pattern.
- No-chrome won: the image *is* the product. Adding a card around
  it competes with the image. The 3/4 aspect image with caption below
  is the editorial/Net-a-Porter pattern and is right for this app.

**White tab bar with brown active (not espresso, not cream):**
- Espresso considered (was my preferred design choice): made the
  bottom of the screen the loudest part of the UI, fighting the content.
- Cream considered: continuous with header but made the whole screen
  feel monotone.
- White won: lets content be the hero, brown active state is enough
  signal without dominating, conventional pattern users immediately
  understand.

**Drop category icons (vs. keep with redesigned icons):**
- Keep with redesigned: would maintain visual continuity for users.
- Drop: cleaner, more Net-a-Porter, lets the chip text breathe.
- Dropped because the category strip is small surface area; the
  cleanup is worth it. Icons can come back in M6+ if usage data
  shows users miss them.

**One-shot rollout (vs. phased palette → typography → cards):**
- Phased rollout considered: would let users adapt gradually.
- One-shot won: phased adds 3× the deploy/test cycles for the same
  endpoint, and the M2 design system is internally consistent —
  shipping half of it would look broken.

---

## Implementation history (appended after rollout)

This section captures what actually shipped vs what the spec
anticipated. Added after M6f-1 to keep the spec honest. The
"Locked decisions log" above remains as the original contract;
this section documents how implementation deviated from it, and
why.

### Phases as shipped

The original spec listed M6c / M6d / M6e / M6f as four phases. In
practice we shipped six commits across those four phases:

  M6c (b54572c)         Header + search + categories
  M6d (f5f9ae6)         Section headers + product cards
  M6d-fix1 (feffbd4)    Card chrome added (real-use feedback)
  M6e (f91e6f2)         Vendor featured + full Taiga divorce
  M6f-1 (dc1a3e6)       Tab bar + wishlist modal
  M6f-2 (this commit)   Dead CSS cleanup + this spec amendment

Total: ~1100 lines of HTML/SCSS/TS modified across account.page,
plus the M2 design tokens already in place (no new tokens added).

### Locked-decisions overrides

Four decisions in the original "Locked decisions log" were
overridden during implementation. Documenting each here so the
log isn't silently wrong.

**Override 1: Category icons kept (M6c)**

Original decision: "Drop category icons. Cleaner, more Net-a-
Porter, lets the chip text breathe."

What shipped: Icons kept inside the M6c chips, restyled smaller
and brown.

Reason: With 7 categories (not the 3 in the original mockup),
icon recognition aids fast scanning. The mockup-with-3-chips
case argued for cleaner-without-icons; the real-with-7-chips
case argues the other way. Discovered during M6c implementation
when grepping the actual category list.

**Override 2: Product card chrome added (M6d-fix1)**

Original decision: "Product card with no chrome. The image IS
the product. Adding a card around it competes with the image."

What shipped: White card with soft warm-brown-tinted shadow and
12px outer radius wrapping image + caption together.

Reason: User feedback after M6d deploy — cards looked too
floaty without surrounding chrome. Reference image showed the
white-card + soft-shadow treatment which groups image and
caption into a clear object and works on any background. The
"no chrome" direction relied on calm, consistent page
backgrounds; in practice account.page transitions across beige,
white, and other surfaces, so background-independent cards are
correct. Two-layer shadow (tight contact + softer ambient)
chosen for warmth; warm-brown-tint rather than neutral grey to
harmonize with the M2 palette.

**Override 3: Vendor card hero-led with first product (M6e)**

Original decision: "Vendor featured card: hero photo with bottom
gradient, store name in Poltawski regular 18px white, gold star
+ rating in Inter 11px, 'FEATURED COLLECTION' label, 3-square
preview row."

What shipped: Same visual direction, but the hero is the
vendor's first product (not a separate vendor hero photo).
Label changed to "FEATURED STORE" (translate key) for unambiguous
framing. Thumb row uses remaining products, count varies based
on what the vendor has (not fixed at 3 squares).

Reason: Spec assumed a vendor hero photo field in the data
model. There isn't one — vendors only have product images. User
chose Option A (use first product as hero) over Option B (data-
led header layout) when shown both. The "FEATURED STORE" label
is a small clarification I made during impl: the *store* is
what's featured, the products are samples. This avoids the
"is this card featuring the product or the store?" cognitive
misfire.

**Override 4: Modal submit button skipped (M6f-1)**

Original decision: "Bottom: brown <ion-button expand='block'>
'Add to closet' matching M6a Pattern A."

What shipped: No bottom submit button. Tap any list row to add
to that closet directly.

Reason: Production interaction is tap-to-add-from-list-row, not
select-then-submit. Adding a submit button would require
restructuring addToCloset() to take selection state first, which
is a UX change not a redesign. Spec was written without seeing
the actual interaction. The tap-to-add pattern is also more
mobile-native and faster.

### Sections that didn't apply

Two sections of the spec turned out not to apply to the
production code:

**Popular stores section (M6e)**

Spec called for a redesigned Popular Stores carousel with 80px
round store-logo items. Production has only an orphan
`<h2>{{ 'popular_stores' | translate }}</h2>` heading with no
items underneath it — the vendor featured cards effectively
serve as "popular stores" content. The orphan heading was
dropped during M6e. A real Popular Stores feature with items
would be separate product work, not redesign.

**Empty state (M6e)**

Spec called for an M2-styled empty state with brand-icon +
heading + subtext + brown CTA button. Production has no rendered
empty state — only a loader (when `is_loading`) and an
`<ion-infinite-scroll>` (when `!is_empty`). Users with no
content currently see... nothing. Adding a real empty state
would be a feature addition, not redesign. Skipped per user
direction during M6e.

### What's still pending

Three items shipped behind-the-spec (deferred or partially done):

**Other pages' tab bars.** M6f-1 redesigned the tab bar in
account.page only. The deep red `#7C2108` footer still appears
on every other page in the app (cart, orders, search, etc.).
Each page has its own copy of the deep-red footer styling.
Migrating those is its own work — probably an "M7 page sweep"
rather than continued M6.

**Tab active state route binding.** M6f-1 hardcoded Home as the
active tab (matching production's pre-existing hardcoded
`text-decoration: underline` behavior). Wiring the active class
to the current route would require Router injection + route
subscription + conditional class binding. Separate work.

**Cart tab bug.** M6f-1 fixed `tab="explore"` on the Cart tab to
`tab="cart"`. If Ionic's tab-outlet machinery is wired up
elsewhere and was relying on the duplicate, this could surface
as a bug. Click handler `user_cart()` is unchanged so primary
navigation works regardless. Flagged for monitoring.

### Token audit

The spec's "Color tokens used by this redesign" table listed 12
semantic tokens. All 12 were already present in M2's
`_tokens.scss` at exactly the values listed. Zero new tokens
were added during M6 implementation. This is a sign M2 was
designed thoroughly enough to support real page redesigns
without extension.

### Taiga divorce

After M6e, account.page is the **first page in the entire
codebase** with zero `@taiga-ui/*` imports, zero `<tui-*>`
elements, and zero `tui*` directive references. M6f-2's CSS
cleanup completes the picture by removing the legacy SCSS that
referenced Taiga's `tui-icon` selector inside dead style blocks.

The page is now fully on Ionic + ax-mobile primitives + M2 tokens.

### Lessons for the next page redesign

When a future page (cart, orders, settings, etc.) gets the same
treatment, the things this redesign's experience suggests:

1. **Audit the actual data + interactions BEFORE writing the
   spec, not just the visible HTML.** Three of four overrides
   above came from the spec assuming features (hero photo,
   submit button) or items (Popular Stores) that the production
   code didn't have.

2. **Phases of 1-2 sections each work better than 4 sections in
   one phase.** M6c through M6f-1 each shipped a discrete,
   testable visible change. User confirmation between phases
   caught one bug early (M6d-fix1 chrome) that would have been
   harder to address if M6d-f had been one commit.

3. **Spec-as-direction beats spec-as-contract.** Four overrides
   on six sections sounds like a lot, but the high-level
   direction (palette, typography, hierarchy, calmer rhythm)
   was right and held. The mistakes were all in stylistic
   specifics that needed implementation reality to settle.

4. **Bug-fixes-in-passing should be flagged in commit messages,
   not silently absorbed.** The Cart tab bug (`tab="explore"`),
   the M6c missing search icon, the M4b inline-template miss in
   cart-icon.component.ts — all caught and fixed during M6
   implementation. All flagged openly so they're discoverable
   later.
