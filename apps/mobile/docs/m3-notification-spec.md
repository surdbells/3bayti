# M3 — Notification Component Spec

**Status:** Locked. Implementation deferred to a separate session.
**Captured:** 2026-04-26
**Build phase:** M3a (build primitive) + M3b (migrate 34 callers)

This document is the authoritative spec for the abayti mobile notification
component. It replaces the original "swap toast library" plan, which was
based on a misunderstanding — the actual goal is to remove third-party
toast libraries entirely and build a notification primitive that lives
inside the M2 design system.

---

## Why this exists

**Current state:**
- 34 TS files import `HotToastService` from `@ngxpert/hot-toast@3`
- Library is third-party, generic, doesn't honor M2 design tokens
- Calls use `toast.success(msg, opts)`, `toast.error(msg, opts)`,
  `toast.loading(msg, opts)` with `position: 'top-center'` and
  `className: 'hot-toast-container'`

**Target state:**
- `@ngxpert/hot-toast` removed from package.json
- Custom `<ax-notification>` component in `src/app/shared/ax-mobile/notification/`
- `AxNotificationService` with hot-toast-compatible API surface
- Visual treatment matches M2 — white surfaces, soft warm shadows,
  ax color tokens, Poltawski for optional titles

**Why not just keep the library:**
- The notification surface is one of the most-seen pieces of UI. If it
  doesn't honor the design system, the whole app's premium feel is
  undermined three times per shopping session.
- A custom component is small. Building one is cheaper than the
  long-term cost of every notification looking like a generic web app.

---

## Visual treatment

### Locked design decisions

**Style: subtle (white card + colored status dot).** No accent stripe,
no filled tinted card. The notification reads as a quiet card with a
small colored dot that communicates status. Restraint is the brand.

**Surface:** white (`var(--ax-palette-neutral-0)`).
**Border:** none.
**Border-radius:** 12px (`var(--ax-radius-lg)`).
**Shadow:** lifted warm shadow — slightly stronger than card shadow to
sell elevation since notifications float above content.
```css
box-shadow:
  0 6px 20px rgba(46, 36, 28, 0.10),
  0 2px 4px  rgba(46, 36, 28, 0.06);
```
**Padding:** 14px vertical, 16px horizontal.

### Status dots

| Status | Dot color | Dot size |
|--------|-----------|----------|
| Success | `var(--ax-palette-success-500)` (#2e7d40) | 8px |
| Info | `var(--ax-palette-info-500)` (#2f5b75) | 8px |
| Warning | `var(--ax-palette-warning-400)` (#e5a03a) | 8px |
| **Error** | `var(--ax-palette-danger-500)` (#a53826) | **12px** (locked exception for visibility) |
| Loading | `var(--ax-color-bg-brand)` (#906952 / brown-500) | 8px, pulsing |

Dots are perfect circles, vertically centered with the message line
when no title; vertically aligned to top of title line when title is
present (offset by ~6px so the dot lines up with the cap height of the
title text).

### Loading dot animation

Subtle scale + opacity pulse. Slow enough to feel calm, not anxious:

```css
@keyframes ax-notification-pulse {
  0%, 100% { opacity: 1;    transform: scale(1); }
  50%      { opacity: 0.55; transform: scale(0.85); }
}
.ax-notification__dot--loading {
  animation: ax-notification-pulse 1.4s ease-in-out infinite;
}
```

### Layout

Inside the card, two elements are arranged horizontally with `gap: 12px`:

1. **Status dot** — 8px or 12px circle (see table above), `flex-shrink: 0`.
2. **Content area** — `flex: 1`, contains either:
   - Just `<message>`, or
   - `<title>` stacked above `<message>`

When **no title**: dot is vertically centered with the message
(`align-items: center`).
When **title present**: dot aligns with the title cap height
(`align-items: flex-start`, dot has `margin-top: 6px`).

### Typography

- **Title (optional):** Poltawski Nowy display, 14px, weight 600,
  letter-spacing tight, color `var(--ax-color-text-primary)`,
  line-height 1.25.
- **Message:** Inter body, 13px, weight 400, color
  `var(--ax-color-text-primary)` (when standalone) or
  `var(--ax-color-text-secondary)` (when below a title — message becomes
  supportive copy), line-height 1.4.

Why message-color shifts when there's a title: with title + message,
the title carries the headline and the message is supporting detail.
Visual hierarchy demands the message be slightly muted. Without a
title, the message IS the headline and gets full text-primary weight.

### When to use a title

Convention for engineers using the API:

**Use a title for** high-stakes notifications:
- Order placed / payment received
- Payment failed / order canceled
- Account changes (email confirmed, password reset)
- Any state where the user benefits from a clear headline + detail

**Skip the title for** lightweight feedback:
- "Added to bag", "Removed from wishlist"
- Validation errors ("Address could not be verified")
- Routine errors ("Couldn't reach the server")
- Loading states ("Placing your order…")

If notifications are mostly headlines mixed with mostly title-less ones,
the visual rhythm gets uneven. Defaulting to no-title and reaching for
title only for the right moments keeps the system calm.

---

## Behavior

### Animation

- **Enter:** slide down from above viewport + fade in. 200ms ease-out.
  Translate from `Y(-12px)` to `Y(0)`. Opacity from 0 to 1.
- **Exit:** slide up + fade out. 180ms ease-in.
  Translate from `Y(0)` to `Y(-12px)`. Opacity from 1 to 0.
- **Reduce-motion preference:** if `prefers-reduced-motion: reduce`,
  cross-fade only (no translate). Duration drops to 100ms.

### Auto-dismiss

| Status | Default duration |
|--------|------------------|
| Success | 3000ms |
| Info | 4000ms |
| Warning | 5000ms |
| Error | 6000ms |
| Loading | sticky (no auto-dismiss; must be dismissed by code) |

Each call accepts a `duration` option that overrides the default.
Pass `duration: 0` for "show indefinitely until tapped or dismissed
programmatically."

### Tap-to-dismiss

Tapping anywhere on a notification dismisses it immediately. No
explicit X close button — keeps the visual minimal. Tap area is the
entire card.

### Stacking

Maximum 3 notifications visible at once. Stacked vertically with 8px
gap, newest at top. When a 4th notification fires:
- Oldest visible notification animates out (slide up + fade).
- New notification animates in at the top of the stack.
- Older notifications shift down 1 slot via 180ms transform animation.

### Position

- **Default:** `top-center`.
- **Supported:** `top-center`, `bottom-center`, `top-right`, `top-left`,
  `bottom-right`, `bottom-left`.
- **Edge offset on mobile:** 16px from the safe-area-inset edge.
  (Status bar / home indicator / notch all respected via
  `env(safe-area-inset-top)` and `env(safe-area-inset-bottom)`.)

### Width

- **Mobile (< 640px):** edge-to-edge with 16px horizontal margins.
- **Tablet / desktop (≥ 640px):** fixed width 480px max, centered
  (or aligned to position if `top-right` etc.).

### Haptics

Light haptic feedback on Capacitor Haptics API:
- **Error:** medium impact
- **Success:** light impact
- **Other (info, warning, loading):** none

iOS only — Android haptics are inconsistent across vendors. If Capacitor
Haptics plugin is unavailable (e.g. PWA without native bridge), silently
skip without errors.

Globally disablable via `AxNotificationService.setHaptics(false)`.

### RTL / Arabic

When `<html dir="rtl">`:
- Slide animations flip horizontally (e.g. `top-right` becomes the
  visual `top-left`).
- Dot moves to right side of card.
- Text aligns right.
- All padding / margin / gap values mirror.

Implementation: use logical CSS properties (`padding-inline-start`,
`margin-inline-start`, etc.) throughout. Avoid `padding-left`.

---

## API

### Service: `AxNotificationService`

```typescript
import { Injectable, signal } from '@angular/core';
import { Hapti­cs, ImpactStyle } from '@capacitor/haptics';

export type AxNotificationStatus =
  | 'success'
  | 'error'
  | 'warning'
  | 'info'
  | 'loading';

export type AxNotificationPosition =
  | 'top-center' | 'bottom-center'
  | 'top-right'  | 'top-left'
  | 'bottom-right' | 'bottom-left';

export interface AxNotificationOptions {
  /** Optional title; rendered in Poltawski display above message */
  title?: string;
  /** Auto-dismiss after this many ms. 0 = sticky. */
  duration?: number;
  /** Where on screen to anchor */
  position?: AxNotificationPosition;
  /** Stable id for deduplication / programmatic dismiss */
  id?: string;
  /** className appended to the host element for one-off overrides */
  className?: string;
}

export interface AxNotificationRef {
  /** Stable id (auto-generated if not provided) */
  readonly id: string;
  /** Dismiss this notification immediately */
  dismiss(): void;
  /** Update the message in place (for loading -> success/error pattern) */
  update(opts: { status?: AxNotificationStatus; message?: string; title?: string }): void;
}

@Injectable({ providedIn: 'root' })
export class AxNotificationService {
  success(message: string, options?: AxNotificationOptions): AxNotificationRef;
  error(message: string, options?: AxNotificationOptions): AxNotificationRef;
  warning(message: string, options?: AxNotificationOptions): AxNotificationRef;
  info(message: string, options?: AxNotificationOptions): AxNotificationRef;
  loading(message: string, options?: AxNotificationOptions): AxNotificationRef;

  /** Generic show with explicit status */
  show(status: AxNotificationStatus, message: string,
       options?: AxNotificationOptions): AxNotificationRef;

  /** Dismiss by id, or all if no id */
  dismiss(id?: string): void;

  /** Promise-style API for async operations */
  observe<T>(promise: Promise<T>, opts: {
    loading?: string;
    success?: string | ((result: T) => string);
    error?: string | ((err: unknown) => string);
  }): Promise<T>;

  /** Global haptic toggle */
  setHaptics(enabled: boolean): void;
}
```

### Drop-in compatibility with `@ngxpert/hot-toast`

The `success`, `error`, `warning`, `info`, `loading` methods all
accept `(message, options)` exactly like hot-toast. The `position`
option is identical. The return type's `dismiss()` method matches
hot-toast's `close()` behavior — but **renamed to `dismiss()`** for
consistency with the rest of M2's vocabulary.

**Migration path for callers:** the hot-toast `close()` calls don't
appear in any of the 34 mobile callsites today (none use the return
value), so the rename is invisible at the callsite level. M3b's
mechanical sweep is just `import` rename + class name change.

### Example usage

```typescript
import { AxNotificationService } from '@/shared/ax-mobile/notification';

@Component({...})
export class CartPage {
  constructor(private notify: AxNotificationService) {}

  addToBag(item: Product) {
    this.notify.success('Added to bag');
  }

  placeOrder() {
    const ref = this.notify.loading('Placing your order…');
    this.api.checkout().subscribe({
      next: (order) => {
        ref.update({
          status: 'success',
          title: 'Order placed',
          message: 'We\'ll send a confirmation by email',
        });
      },
      error: (err) => {
        ref.update({
          status: 'error',
          title: 'Payment failed',
          message: err.message ?? 'Card was declined. Try a different card.',
        });
      },
    });
  }
}
```

---

## File layout

```
src/app/shared/ax-mobile/notification/
├── ax-notification.component.ts       # Single notification card
├── ax-notification.component.scss     # Component styles (uses ax tokens)
├── ax-notification-host.component.ts  # The portal/stack container
├── ax-notification-host.component.scss
├── ax-notification.service.ts         # Service + ref factory
├── ax-notification.types.ts           # Public types
├── ax-notification.utils.ts           # Helpers (id gen, haptics wrapper)
└── index.ts                           # Public barrel
```

The `host` component is a single instance bootstrapped at app start
(in `app.component.ts`). It listens to a signal in the service and
renders the active notification stack via `*ngFor`. Using a host
component (rather than a dynamic CDK Overlay) keeps the implementation
simple — no Overlay setup, no z-index drama.

---

## Implementation phases

### M3a — Build the primitive

1. Create the file layout above.
2. Implement the host + card components, signal-based state.
3. Implement enter/exit animations using Angular animation API
   (or simple CSS transitions; CSS is preferred for performance).
4. Implement the service with all status methods + `observe()`.
5. Implement haptic integration via `@capacitor/haptics` (already a
   project dependency).
6. Implement RTL handling.
7. Bootstrap `<ax-notification-host>` once in `app.component.ts`.
8. Build, verify, commit, push.

**No callsite migration in M3a.** Both `@ngxpert/hot-toast` and the
new `AxNotificationService` exist side by side. Existing 34 callsites
keep using hot-toast; the new service is unused.

This isolates risk: M3a only adds code; nothing breaks.

### M3b — Migrate callers + remove hot-toast

1. Sweep all 34 TS files with sed:
   ```
   from '@ngxpert/hot-toast' -> from '@/shared/ax-mobile/notification'
   HotToastService -> AxNotificationService
   ```
2. Spot-check call sites for any hot-toast-specific options that
   our new service doesn't support (audit during M3b implementation;
   currently none expected based on the M3 audit).
3. Update `main.ts` — remove `provideHotToastConfig` import + provider.
4. Update `angular.json` — remove `@ngxpert/hot-toast/src/styles/styles.scss`.
5. Update `src/global.scss` — remove `@use "@ngxpert/hot-toast/..."`.
   Replace the `.hot-toast-container { margin-top: 120px !important }`
   rule with `.ax-notification-host { ... }` if any equivalent rule
   is needed (likely not — host positions itself via safe-area).
6. Run `npm uninstall @ngxpert/hot-toast`.
7. Build, verify, commit, push.

---

## Risk notes

**API surface mismatch:** the spec is drop-in for the 34 calls audited
this session. But hot-toast supports more options than we use (`style`,
`iconTheme`, `attributes`). If any code passes those, the migration
silently drops them. **Mitigation:** during M3b, search for `style:`,
`iconTheme:`, `className:` inside hot-toast call sites. If found,
either add support to the new service or migrate the styling to the
new design system.

**Stacking edge case:** if 10 notifications fire in 100ms (e.g. form
validation showing 10 errors at once), the stack of 3 will churn.
**Mitigation:** the service should debounce identical messages within
a 200ms window — second identical call updates the existing
notification's duration rather than creating a new one. (Implementation
detail for M3a.)

**Loading-to-result pattern:** when `ref.update({ status: 'success' })`
is called on a loading notification, the dot color transitions from
brown to green. This should animate (200ms ease) so it doesn't pop.

**Haptics on PWA:** Capacitor Haptics will throw in PWA context.
**Mitigation:** wrap haptic calls in a try/catch that silently swallows
on the web platform. Detect via `Capacitor.isNativePlatform()`.

**Bundle size:** the new component + service should add roughly 4-6 kB
gzipped. `@ngxpert/hot-toast` removal saves ~10-15 kB. Net: bundle
shrinks by 5-10 kB. Capture in M3b's commit message.

---

## What this spec does NOT cover

- **Action buttons inside notifications.** The audit shows 0 callsites
  use action buttons. Adding action support later is non-breaking
  (new optional option), so deferring is safe.
- **Persistent / sticky notifications managed via a list view.** Out
  of scope. Notifications are ephemeral.
- **Notification history / unread badge.** Out of scope.
- **Push notifications.** This is in-app only. Push notifications go
  through Firebase / native plugins separately.

---

## Phase M3 commit log (to be filled during implementation)

```
M3a  [pending]  Build ax-notification primitive
M3b  [pending]  Migrate 34 callers, remove @ngxpert/hot-toast
```

After M3b ships, Phase M3 is complete. Phase M4 starts with Taiga
icons + loader replacement.
