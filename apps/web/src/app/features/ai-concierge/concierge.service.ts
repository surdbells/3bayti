import { Injectable, PLATFORM_ID, inject } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { LocaleService } from '../../core/i18n/locale.service';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import type { Product } from '../catalog/product.model';

/** A concierge recommendation: the v3 listShape product + Ain's one-line reason. */
export type ConciergeCard = Product & { reason?: string };

export interface ConciergeResult {
  interactionId: number | null;
  cards: ConciergeCard[];
}

/** Raw concierge response body (Responder::ok returns it un-enveloped). */
interface ConciergeResponse {
  interaction_id: number | null;
  intent?: unknown;
  products: ConciergeCard[];
}

/**
 * Talks to the Ain concierge endpoints. `ask()` resolves a natural-language
 * query to real, ranked product cards; analytics are split between GA4 (via
 * AnalyticsService) and the server-side ledger (POST /ai/events, keyed by the
 * returned interaction_id). The concierge endpoint is public — the auth token,
 * when present, is attached automatically by the refresh interceptor.
 */
@Injectable({ providedIn: 'root' })
export class ConciergeService {
  private readonly http = inject(RoutedHttpClient);
  private readonly locale = inject(LocaleService);
  private readonly analytics = inject(AnalyticsService);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private static readonly SESSION_KEY = 'ain_session';

  async ask(query: string): Promise<ConciergeResult> {
    this.analytics.event('ai_concierge_query', { query_length: query.length });

    const env = await firstValueFrom(
      this.http.post<ConciergeResponse>('POST /ai/concierge/style', {
        body: {
          query,
          locale: this.locale.current(),
          session_id: this.sessionId(),
          channel: 'WEB',
        },
      }),
    );

    const data = (env.data ?? {}) as ConciergeResponse;
    const cards = Array.isArray(data.products) ? data.products : [];
    this.analytics.event('ai_concierge_results', { count: cards.length });

    return { interactionId: data.interaction_id ?? null, cards };
  }

  /** Best-effort server-side analytics beacon; never blocks or throws. */
  recordEvent(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId(), ...extra };
      this.http.post('POST /ai/events', { body }).subscribe({ next: () => undefined, error: () => undefined });
    } catch {
      // Analytics must never break the page (e.g. route table not yet loaded).
    }
  }

  private sessionId(): string {
    if (!this.isBrowser) {
      return 'ssr';
    }
    try {
      const existing = localStorage.getItem(ConciergeService.SESSION_KEY);
      if (existing) {
        return existing;
      }
      const id = this.uuid();
      localStorage.setItem(ConciergeService.SESSION_KEY, id);
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
