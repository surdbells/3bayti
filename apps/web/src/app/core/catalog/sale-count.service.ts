import { Injectable, Signal, inject, signal } from '@angular/core';
import { RoutedHttpClient } from '../http/routed-http-client';

/**
 * Shared on-sale product count.
 *
 * Backs the count badge on the Discounted shortcuts (home category tile +
 * header nav link). One cheap limit=1 read of GET /products?sale=true, we only
 * need meta.total, not the rows. Loaded once and shared, so multiple consumers
 * don't each fire the request. Degrades to 0 on error (badge hidden).
 */
@Injectable({ providedIn: 'root' })
export class SaleCountService {
  private readonly routed = inject(RoutedHttpClient);

  private readonly _count = signal(0);
  /** Total on-sale products (0 until loaded, or on failure). */
  readonly count: Signal<number> = this._count.asReadonly();

  private loaded = false;

  /** Fetch the on-sale total once. Idempotent, safe to call from every consumer. */
  load(): void {
    if (this.loaded) {
      return;
    }
    this.loaded = true;
    this.routed.get<unknown[]>('GET /products', { query: { sale: true, limit: 1 } }).subscribe({
      next: (res: unknown) => {
        const total = (res as { meta?: { total?: number } })?.meta?.total;
        this._count.set(Number(total ?? 0) || 0);
      },
      error: () => this._count.set(0),
    });
  }
}
