import { Observable, Subscription } from 'rxjs';

import { CollectionRef, membershipMapFromApi } from './collection-builder.model';

/** Contract A accepts at most this many product ids per request. */
export const MEMBERSHIP_BATCH_SIZE = 200;

/**
 * Per-product collection memberships for the builder's cards, fetched in
 * batches from GET /admin/collections/memberships?product_ids=… (contract A).
 *
 * - ensure(ids) fetches only ids that are neither cached nor in flight, in
 *   chunks of up to 200, so a page of 20 cards is ONE request and a card
 *   re-rendering never refetches.
 * - invalidate() drops the cache; refresh(ids) refetches those ids but keeps
 *   showing their last-known memberships until the answer lands (no flicker
 *   after a save). Either way a response that was in flight before is
 *   ignored (generation check), so stale data never overwrites the refetch.
 * - A failed batch is remembered so the cards stop showing "loading" and show
 *   a distinct "couldn't check" state instead (a failure must never look like
 *   "in no other collection"). ensure() skips failed ids, so a broken endpoint
 *   isn't hit again on every page; retry(ids) / retryFailed() fetch them
 *   again, and invalidate() / refresh() clear the failures too.
 */
export class CollectionMembershipCache {
  private readonly cache = new Map<number, CollectionRef[]>();
  private readonly inflight = new Set<number>();
  private readonly failed = new Set<number>();
  private readonly subs = new Subscription();
  private generation = 0;
  /** The error of the most recent failed batch. */
  private lastFailure: unknown = null;

  constructor(private readonly fetchBatch: (ids: number[]) => Observable<unknown>) {}

  /** Collections the product is in, or undefined while unknown (loading / failed). */
  get(productId: number): CollectionRef[] | undefined {
    return this.cache.get(productId);
  }

  isLoading(productId: number): boolean {
    return this.inflight.has(productId);
  }

  /** Loading with nothing to show yet (the card shows a placeholder chip). */
  isPending(productId: number): boolean {
    return this.inflight.has(productId) && !this.cache.has(productId);
  }

  hasFailed(productId: number): boolean {
    return this.failed.has(productId);
  }

  /**
   * The product's memberships are unknown because its lookup failed and there
   * is no earlier answer to fall back on: cards show "couldn't check".
   */
  isUnknown(productId: number): boolean {
    return this.failed.has(productId) && !this.cache.has(productId);
  }

  /** The error of the most recent failed batch, while any failure stands. */
  get lastError(): unknown {
    return this.failed.size ? this.lastFailure : null;
  }

  /** Fetch memberships for every id not already known or loading. */
  ensure(ids: Iterable<number>): void {
    const missing: number[] = [];
    const seen = new Set<number>();
    for (const raw of ids) {
      const id = Number(raw);
      if (!Number.isInteger(id) || id <= 0 || seen.has(id)) continue;
      seen.add(id);
      if (this.cache.has(id) || this.inflight.has(id) || this.failed.has(id)) continue;
      missing.push(id);
    }
    for (let i = 0; i < missing.length; i += MEMBERSHIP_BATCH_SIZE) {
      this.load(missing.slice(i, i + MEMBERSHIP_BATCH_SIZE));
    }
  }

  /** Forget everything (memberships changed server-side). */
  invalidate(): void {
    this.generation++;
    this.cache.clear();
    this.inflight.clear();
    this.failed.clear();
  }

  /**
   * Refetch `ids` (memberships changed server-side), keeping their current
   * values on screen until the new ones arrive. Everything else is dropped.
   */
  refresh(ids: Iterable<number>): void {
    const wanted = new Set<number>();
    for (const raw of ids) {
      const id = Number(raw);
      if (Number.isInteger(id) && id > 0) wanted.add(id);
    }
    const kept = new Map([...this.cache].filter(([id]) => wanted.has(id)));
    this.invalidate();
    kept.forEach((v, id) => this.cache.set(id, v));
    const list = [...wanted];
    for (let i = 0; i < list.length; i += MEMBERSHIP_BATCH_SIZE) {
      this.load(list.slice(i, i + MEMBERSHIP_BATCH_SIZE));
    }
  }

  /** Forget the failures so ensure() tries those ids again. */
  retryFailed(): void {
    this.failed.clear();
  }

  /**
   * Look `ids` up again when their last lookup failed (even when an older
   * answer is still shown, e.g. a failed refresh), and fetch any of them that
   * are simply unknown. Ids in flight are left alone.
   */
  retry(ids: Iterable<number>): void {
    const again: number[] = [];
    const rest: number[] = [];
    for (const raw of ids) {
      const id = Number(raw);
      if (this.failed.delete(id) && !this.inflight.has(id)) again.push(id);
      else rest.push(id);
    }
    for (let i = 0; i < again.length; i += MEMBERSHIP_BATCH_SIZE) {
      this.load(again.slice(i, i + MEMBERSHIP_BATCH_SIZE));
    }
    this.ensure(rest);
  }

  dispose(): void {
    this.subs.unsubscribe();
  }

  private fail(ids: number[], gen: number, err: unknown): void {
    if (gen !== this.generation || !ids.length) return;
    for (const id of ids) {
      this.inflight.delete(id);
      this.failed.add(id);
    }
    this.lastFailure = err;
  }

  private load(batch: number[]): void {
    const gen = this.generation;
    batch.forEach((id) => this.inflight.add(id));
    this.subs.add(
      this.fetchBatch(batch).subscribe({
        next: (res) => {
          if (gen !== this.generation) return;
          const map = membershipMapFromApi(res, batch);
          for (const id of batch) {
            this.inflight.delete(id);
            this.cache.set(id, map.get(id) ?? []);
          }
        },
        error: (err: unknown) => this.fail(batch, gen, err ?? new Error('Membership lookup failed')),
        // Completed without a response: never leave the cards "loading".
        complete: () =>
          this.fail(batch.filter((id) => this.inflight.has(id)), gen, new Error('No response from the memberships lookup')),
      }),
    );
  }
}
