import { Injectable, PLATFORM_ID, inject } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import type { Product } from './product.model';

/** A complement: the listShape product + Ain's reason + its category slug. */
export type CompleteLookItem = Product & { reason?: string; complement_category?: string };

export interface CompleteLookResult {
  interactionId: number | null;
  items: CompleteLookItem[];
}

interface CompleteLookResponse {
  interaction_id: number | null;
  seed_product_id: number;
  items: CompleteLookItem[];
}

/**
 * Fetches "Complete the Look" complements for a product (by v3 id) and beacons
 * the add-the-look analytics events. Read-only + best-effort: any failure yields
 * an empty result so the PDP strip simply doesn't render.
 */
@Injectable({ providedIn: 'root' })
export class CompleteTheLookService {
  private readonly http = inject(RoutedHttpClient);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private static readonly SESSION_KEY = 'ain_session';

  async forProduct(id: number, limit = 6): Promise<CompleteLookResult> {
    if (!id) {
      return { interactionId: null, items: [] };
    }
    try {
      const res = await firstValueFrom(
        this.http.get<CompleteLookResponse>('GET /products/:id/complete-the-look', {
          params: { id },
          query: { limit },
        }),
      );
      const data = (res.data ?? {}) as CompleteLookResponse;
      return {
        interactionId: data.interaction_id ?? null,
        items: Array.isArray(data.items) ? data.items : [],
      };
    } catch {
      return { interactionId: null, items: [] };
    }
  }

  /** Best-effort analytics beacon; never throws into the caller. */
  recordEvent(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId(), ...extra };
      this.http.post('POST /ai/events', { body }).subscribe({ next: () => undefined, error: () => undefined });
    } catch {
      // analytics must never break the page
    }
  }

  private sessionId(): string {
    if (!this.isBrowser) {
      return 'ssr';
    }
    try {
      const existing = localStorage.getItem(CompleteTheLookService.SESSION_KEY);
      if (existing) {
        return existing;
      }
      const id = this.uuid();
      localStorage.setItem(CompleteTheLookService.SESSION_KEY, id);
      return id;
    } catch {
      return this.uuid();
    }
  }

  private uuid(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
      return crypto.randomUUID();
    }
    return 'w-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
  }
}
