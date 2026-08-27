import { HttpInterceptorFn } from '@angular/common/http';
import { inject } from '@angular/core';
import { CurrencyService } from './currency.service';

/**
 * currencyInterceptor, appends `?currency=XXX` to all outbound
 * catalog API calls when the visitor has chosen a non-AED display
 * currency (M3.2.W.3).
 *
 * Only applied to requests that hit catalog read endpoints:
 *   /v3/products, /v3/products/facets,
 *   /v3/categories, /v3/categories/:slug
 *
 * The backend's ProductSerializer reads the `currency` query param via
 * the CurrencyMiddleware and emits a dual-amount money shape:
 *   { amount: <AED>, currency: 'AED', source_amount: <XXX>, source_currency: 'XXX' }
 * when non-AED is requested, or the standard { amount, currency }
 * when AED / absent. Product-card and detail rendering reads
 * `source_amount`/`source_currency` to show the converted price.
 *
 * Non-catalog endpoints (auth, cart, orders, webhooks) are intentionally
 * left untouched, they always deal in AED.
 */
const CATALOG_PATH_PREFIXES = [
  '/v3/products',
  '/v3/categories',
];

export const currencyInterceptor: HttpInterceptorFn = (req, next) => {
  const currencyService = inject(CurrencyService);
  const param = currencyService.queryParam();

  // Only modify catalog reads, only when non-AED, only GET requests.
  const isCatalogGet = req.method === 'GET'
    && CATALOG_PATH_PREFIXES.some((prefix) => req.url.includes(prefix));

  if (!param || !isCatalogGet) {
    return next(req);
  }

  const modified = req.clone({
    params: req.params.set('currency', param),
  });
  return next(modified);
};
