import { Injectable, computed, inject, signal } from '@angular/core';
import { Observable, from, of } from 'rxjs';
import { concatMap, finalize, map, reduce, shareReplay, tap } from 'rxjs/operators';

import { PortalCrudAdapter } from './portal-crud-adapter';

// ─────────────────────────────────────────────────────────────────────────
// Models (mirror the admin Try-On API contract, T1–T4)
// ─────────────────────────────────────────────────────────────────────────

/** Why a product's garment (primary) image can't be used for Try-On. */
export type TryOnIneligibleReason = 'no_image' | 'external_image' | 'unsupported_format';

/** Why a bulk update skipped a product. */
export type TryOnSkipReason = 'not_found' | TryOnIneligibleReason;

export interface TryOnEligibility {
  readonly eligible: boolean;
  /** null when eligible, or when the API sent a reason this client doesn't know. */
  readonly reason: TryOnIneligibleReason | null;
}

/** GET /admin/try-on/status. */
export interface TryOnStatus {
  /** The try-on provider is live (TRYON_ENABLED + AI_ENABLED + API key). */
  readonly available: boolean;
  /** Raw TRYON_ENABLED env flag. */
  readonly tryon_flag: boolean;
  /** Raw AI_ENABLED env flag. */
  readonly ai_enabled: boolean;
  /** Products with try_on_enabled = true. */
  readonly enabled_products: number;
  /** Garment image formats the worker accepts, e.g. ['jpeg','png','webp']. */
  readonly eligible_formats: readonly string[];
}

export interface TryOnVendorRef {
  readonly id: number;
  readonly name: string;
  readonly slug: string;
}

/** One product row of GET /admin/try-on/products (also PUT /admin/products/:id/try-on). */
export interface TryOnProduct {
  /** v3 product id. */
  readonly id: number;
  readonly slug: string;
  readonly name: string;
  readonly status: string;
  readonly vendor: TryOnVendorRef | null;
  readonly primary_image_url: string | null;
  /** The raw stored flag (not gated by provider availability). */
  readonly try_on_enabled: boolean;
  /** null when the API didn't say (treated as "unknown", never as eligible). */
  readonly eligibility: TryOnEligibility | null;
}

export interface TryOnProductQuery {
  /** Name / slug substring. */
  readonly q?: string | null;
  /** true = enabled only, false = not enabled only, null/undefined = all. */
  readonly enabled?: boolean | null;
  /** 1-based page. */
  readonly page?: number;
  readonly limit?: number;
}

export interface TryOnProductPage {
  readonly items: readonly TryOnProduct[];
  readonly total: number;
  readonly limit: number;
  readonly offset: number;
  readonly hasMore: boolean;
}

export interface TryOnBulkSkip {
  readonly id: number;
  readonly reason: TryOnSkipReason;
}

/** POST /admin/try-on/products/bulk. */
export interface TryOnBulkResult {
  readonly updated: readonly number[];
  readonly skipped: readonly TryOnBulkSkip[];
}

export const TRY_ON_ELIGIBLE_FORMATS: readonly string[] = ['jpeg', 'png', 'webp'];
/** Most product ids the bulk endpoint takes per call. */
export const TRY_ON_BULK_MAX = 200;
export const TRY_ON_PAGE_SIZE_DEFAULT = 20;
export const TRY_ON_PAGE_SIZE_MAX = 100;
/** 422 code for "enable" on a product whose garment image can't be used. */
export const TRYON_IMAGE_INELIGIBLE = 'TRYON_IMAGE_INELIGIBLE';

const INELIGIBLE_REASONS: readonly TryOnIneligibleReason[] = ['no_image', 'external_image', 'unsupported_format'];
const SKIP_REASONS: readonly TryOnSkipReason[] = ['not_found', ...INELIGIBLE_REASONS];

// ─────────────────────────────────────────────────────────────────────────
// Parsers (pure; tolerate the { data } envelope or a raw body)
// ─────────────────────────────────────────────────────────────────────────

function isObject(v: unknown): v is Record<string, any> {
  return v !== null && typeof v === 'object' && !Array.isArray(v);
}

/** `{ data: {...} }` → the inner object; anything else is returned as-is. */
function unwrap(res: unknown): any {
  return isObject(res) && isObject(res['data']) ? res['data'] : res;
}

function toInt(v: unknown, fallback = 0): number {
  const n = Math.trunc(Number(v));
  return Number.isFinite(n) ? n : fallback;
}

function toPositiveId(v: unknown): number | null {
  const n = Number(v);
  return Number.isInteger(n) && n > 0 ? n : null;
}

export function isIneligibleReason(v: unknown): v is TryOnIneligibleReason {
  return typeof v === 'string' && (INELIGIBLE_REASONS as readonly string[]).includes(v);
}

export function parseTryOnEligibility(raw: unknown): TryOnEligibility | null {
  if (!isObject(raw) || typeof raw['eligible'] !== 'boolean') return null;
  const eligible = raw['eligible'];
  const reason = !eligible && isIneligibleReason(raw['reason']) ? raw['reason'] : null;
  return { eligible, reason };
}

export function parseTryOnStatus(res: unknown): TryOnStatus {
  const s = unwrap(res);
  const formats = Array.isArray(s?.eligible_formats)
    ? s.eligible_formats.filter((f: unknown): f is string => typeof f === 'string' && f !== '')
    : [];
  return {
    available: s?.available === true,
    tryon_flag: s?.tryon_flag === true,
    ai_enabled: s?.ai_enabled === true,
    enabled_products: Math.max(0, toInt(s?.enabled_products)),
    eligible_formats: formats.length ? formats : TRY_ON_ELIGIBLE_FORMATS,
  };
}

/** One product item; null when it has no usable v3 id. */
export function parseTryOnProduct(raw: unknown): TryOnProduct | null {
  const p = unwrap(raw);
  if (!isObject(p)) return null;
  const id = toPositiveId(p['id']);
  if (id === null) return null;
  const v = p['vendor'];
  const vendorId = isObject(v) ? toPositiveId(v['id']) : null;
  const image = p['primary_image_url'];
  return {
    id,
    slug: String(p['slug'] ?? ''),
    name: String(p['name'] ?? ''),
    status: String(p['status'] ?? ''),
    vendor: isObject(v) && vendorId !== null
      ? { id: vendorId, name: String(v['name'] ?? ''), slug: String(v['slug'] ?? '') }
      : null,
    primary_image_url: typeof image === 'string' && image.trim() !== '' ? image : null,
    try_on_enabled: p['try_on_enabled'] === true,
    eligibility: parseTryOnEligibility(p['eligibility']),
  };
}

/**
 * GET /admin/try-on/products → page. Reads the admin list envelope
 * `{ data: [...], meta: { total, limit, offset, has_more } }`; `limit`/`offset`
 * fall back to what was requested when the meta omits them.
 */
export function parseTryOnPage(res: unknown, limit: number, offset: number): TryOnProductPage {
  const body: any = res;
  const list: unknown[] = Array.isArray(body?.data)
    ? body.data
    : Array.isArray(body?.items) ? body.items : Array.isArray(body) ? body : [];
  const items = list.map(parseTryOnProduct).filter((p): p is TryOnProduct => p !== null);
  const meta = isObject(body?.meta) ? body.meta : {};
  const total = Math.max(items.length, toInt(meta['total'], items.length));
  const echoedLimit = toInt(meta['limit'], limit) || limit;
  const echoedOffset = Math.max(0, toInt(meta['offset'], offset));
  const hasMore = typeof meta['has_more'] === 'boolean'
    ? meta['has_more']
    : echoedOffset + items.length < total;
  return { items, total, limit: echoedLimit, offset: echoedOffset, hasMore };
}

export function parseTryOnBulk(res: unknown): TryOnBulkResult {
  const b = unwrap(res);
  const updated: number[] = Array.isArray(b?.updated)
    ? b.updated.map(toPositiveId).filter((n: number | null): n is number => n !== null)
    : [];
  const skipped: TryOnBulkSkip[] = Array.isArray(b?.skipped)
    ? b.skipped
        .filter((s: unknown) => isObject(s) && toPositiveId(s['id']) !== null)
        .map((s: Record<string, unknown>) => ({
          id: toPositiveId(s['id']) as number,
          reason: (SKIP_REASONS as readonly string[]).includes(String(s['reason']))
            ? (s['reason'] as TryOnSkipReason)
            : 'not_found',
        }))
    : [];
  return { updated, skipped };
}

/** Positive, de-duplicated ids (order kept), split into API-sized chunks. */
export function chunkTryOnIds(ids: readonly unknown[], size = TRY_ON_BULK_MAX): number[][] {
  const seen = new Set<number>();
  const clean: number[] = [];
  for (const raw of ids) {
    const id = toPositiveId(raw);
    if (id !== null && !seen.has(id)) {
      seen.add(id);
      clean.push(id);
    }
  }
  const chunks: number[][] = [];
  for (let i = 0; i < clean.length; i += size) chunks.push(clean.slice(i, i + size));
  return chunks;
}

/** The v3 error body of a failed call: `{ code, message, details? }` (or null). */
export function tryOnApiError(err: any): { code: string; message: string; reason: TryOnIneligibleReason | null } | null {
  const body = err?.error?.error;
  if (!isObject(body)) return null;
  const details = isObject(body['details']) ? body['details'] : {};
  return {
    code: String(body['code'] ?? ''),
    message: typeof body['message'] === 'string' ? body['message'] : '',
    reason: isIneligibleReason(details['reason']) ? details['reason'] : null,
  };
}

// ─────────────────────────────────────────────────────────────────────────
// Service
// ─────────────────────────────────────────────────────────────────────────

/**
 * Admin client for Virtual Try-On product enablement (products.try_on_enabled):
 *
 *   GET  /admin/try-on/status            (products.view)  provider availability + count
 *   GET  /admin/try-on/products          (products.view)  list with image eligibility
 *   PUT  /admin/products/:id/try-on      (products.edit)  toggle one product (v3 id)
 *   POST /admin/try-on/products/bulk     (products.edit)  toggle many (≤200 per call)
 *
 * The status is cached for the session: `ensureStatus()` fetches it once (the
 * side nav uses it to show "Virtual Try-On" only while Try-On is available and
 * fails closed on error), `loadStatus()` always re-reads it (the Try-On page).
 * Everything is keyed by v3 product ids, never legacy ids.
 */
@Injectable({ providedIn: 'root' })
export class TryOnAdminService {
  private readonly adapter = inject(PortalCrudAdapter);

  private readonly _status = signal<TryOnStatus | null>(null);
  /** Last known status (null until loaded, or after a failed first load). */
  readonly status = this._status.asReadonly();
  /** Try-On is live. False while unknown or on error (fail closed). */
  readonly available = computed(() => this._status()?.available === true);

  private inflight$: Observable<TryOnStatus> | null = null;
  /** Session the cached status was fetched for (another sign-in re-fetches). */
  private ensuredFor: string | null = null;

  /** Fetch the status once per signed-in session; errors leave it unknown. */
  ensureStatus(): void {
    const key = this.sessionKey();
    if (this.ensuredFor === key) return;
    if (this.ensuredFor !== null) this._status.set(null);
    this.ensuredFor = key;
    this.loadStatus().subscribe({ error: () => { /* fail closed: nav stays hidden */ } });
  }

  /** Re-read the status (shares an in-flight request) and cache it. */
  loadStatus(): Observable<TryOnStatus> {
    if (this.inflight$) return this.inflight$;
    const req$: Observable<TryOnStatus> = this.adapter.get_v3('GET /admin/try-on/status').pipe(
      map(parseTryOnStatus),
      tap((s) => {
        this._status.set(s);
        this.ensuredFor = this.sessionKey();
      }),
      finalize(() => {
        if (this.inflight$ === req$) this.inflight$ = null;
      }),
      shareReplay({ bufferSize: 1, refCount: false }),
    );
    this.inflight$ = req$;
    return req$;
  }

  /** Keep the cached enabled-product count in step with a successful write. */
  bumpEnabledCount(delta: number): void {
    if (!delta) return;
    this._status.update((s) => (s ? { ...s, enabled_products: Math.max(0, s.enabled_products + delta) } : s));
  }

  listProducts(query: TryOnProductQuery = {}): Observable<TryOnProductPage> {
    const limit = Math.min(TRY_ON_PAGE_SIZE_MAX, Math.max(1, Math.trunc(query.limit ?? TRY_ON_PAGE_SIZE_DEFAULT) || TRY_ON_PAGE_SIZE_DEFAULT));
    const page = Math.max(1, Math.trunc(query.page ?? 1) || 1);
    const offset = (page - 1) * limit;
    const q: Record<string, string | number> = { page, limit, offset };
    const term = (query.q ?? '').trim();
    if (term) q['q'] = term;
    if (query.enabled === true) q['enabled'] = 'true';
    else if (query.enabled === false) q['enabled'] = 'false';
    return this.adapter
      .get_v3('GET /admin/try-on/products', { query: q })
      .pipe(map((res) => parseTryOnPage(res, limit, offset)));
  }

  /** Enable / disable one product (v3 id). Errors pass through (422 TRYON_IMAGE_INELIGIBLE, 404). */
  setEnabled(productId: number, enabled: boolean): Observable<TryOnProduct> {
    return this.adapter
      .put_v3('PUT /admin/products/:id/try-on', { enabled }, { params: { id: String(productId) } })
      .pipe(
        map((res) => {
          const p = parseTryOnProduct(res);
          if (!p) throw new Error('Unexpected try-on response.');
          return p;
        }),
      );
  }

  /** Enable / disable many products, in ≤200-id calls; results are merged. */
  bulkSetEnabled(productIds: readonly number[], enabled: boolean): Observable<TryOnBulkResult> {
    const chunks = chunkTryOnIds(productIds);
    if (!chunks.length) return of({ updated: [], skipped: [] });
    return from(chunks).pipe(
      concatMap((ids) =>
        this.adapter
          .post_v3('POST /admin/try-on/products/bulk', { product_ids: ids, enabled })
          .pipe(map(parseTryOnBulk)),
      ),
      reduce(
        (acc: TryOnBulkResult, r: TryOnBulkResult): TryOnBulkResult => ({
          updated: [...acc.updated, ...r.updated],
          skipped: [...acc.skipped, ...r.skipped],
        }),
        { updated: [], skipped: [] },
      ),
    );
  }

  private sessionKey(): string {
    const s = this.adapter.getSession();
    return String(s?.id ?? '');
  }
}
