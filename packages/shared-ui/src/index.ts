/**
 * @3bayti/shared-ui
 *
 * Cross-app UI components consumed by `apps/web` and `apps/portal`.
 * Mobile uses Ionic and gets its own (Ionic-themed) components.
 *
 * As of M0.4, this package is intentionally empty. Components migrate
 * here in M1+ when individual cross-app patterns emerge:
 *   - Button (M1)
 *   - Modal (M1)
 *   - FormField (M1)
 *   - DataTable (M4 — when portal lands)
 *   - PriceDisplay (M2 — used in catalog)
 *   - VendorBadge (M2)
 *
 * Components are framework-agnostic where possible (just TypeScript +
 * styles); when an Angular-specific wrapper is needed, it lives in a
 * sub-path like `@3bayti/shared-ui/angular`.
 */

// Cloudflare image transforms — framework-agnostic utility + presets
export { cfImage, CF_PRESETS } from './image-transform';
export type { CfImageOptions } from './image-transform';

// Angular pipe — import CfImagePipe in component `imports` array
export { CfImagePipe } from './angular/cf-image.pipe';
export type { CfPresetName } from './angular/cf-image.pipe';
export {};
// CI trigger marker — touched 2026-05-06 to provoke initial workflow run.
