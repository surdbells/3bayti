import { Routes } from '@angular/router';
import { HomeComponent } from './features/home/home';

/**
 * Top-level route table for the public web app.
 *
 * Phase 1: home + a dev-only component preview + categories index.
 * Phase 2 adds: /category/:slug ✓ + /product/:slug + /designer + /designer/:slug.
 *
 * All routes are SSR'd by default; route-level data is fetched server-
 * side via TransferState (see individual feature components).
 */
export const routes: Routes = [
  {
    path: '',
    pathMatch: 'full',
    component: HomeComponent,
    title: '3bayti — Premium Abayas, Kaftans & Modest Wear',
  },
  {
    /* Categories index — `/category`. Server-rendered with TransferState
       so the prerendered HTML embeds the live category list and the
       browser hydrates without a re-fetch. Lazy-loaded so it's its own
       chunk in the build output. */
    path: 'category',
    loadComponent: () =>
      import('./features/categories/categories').then(m => m.CategoriesComponent),
    title: 'Shop by Category · 3bayti',
  },
  {
    /* Category detail — `/category/:slug`. Each of the 8 categories
       is prerendered at build time (see app.routes.server.ts for the
       slug list provider). Renders category metadata + first 20
       products with full SEO + ItemList JSON-LD. */
    path: 'category/:slug',
    loadComponent: () =>
      import('./features/categories/category-detail').then(m => m.CategoryDetailComponent),
    /* Title is set dynamically via SeoService once the data loads;
       the static title here is a fallback for the brief moment before
       hydration and for crawlers that ignore <title> updates. */
    title: 'Shop by Category · 3bayti',
  },
  {
    /* Product detail — `/product/:slug`. The PDP. The 200 most-recent
       products are prerendered at build time (see app.routes.server.ts
       for the cap and slug fetcher). The remaining ~1,400 products
       fall back to runtime SSR — slower first byte but still fully
       indexable.

       Why the cap: building all 1,657 PDPs would push build time past
       Cloudflare Pages' practical limits. 200 prerendered + runtime
       SSR for the long tail is the chosen balance per W2.2 sprint
       planning. */
    path: 'product/:slug',
    loadComponent: () =>
      import('./features/catalog/product-detail').then(m => m.ProductDetailComponent),
    /* Title set dynamically via SeoService. Fallback for crawlers
       that miss the dynamic title update. */
    title: 'Product · 3bayti',
  },
  {
    /* Dev-only component preview. noindex'd via SeoService inside the
       component. Lazy-loaded so it doesn't bloat the production bundle
       for normal users. */
    path: '_dev/components',
    loadComponent: () =>
      import('./features/dev-components/dev-components').then(m => m.DevComponentsComponent),
    title: 'Component preview · 3bayti',
  },
];
