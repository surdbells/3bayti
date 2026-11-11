import { Injectable, PLATFORM_ID, inject } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { firstValueFrom } from 'rxjs';

import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { LocaleService } from '../../core/i18n/locale.service';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import type { Product } from '../catalog/product.model';

/** A concierge recommendation: the v3 listShape product + Ain's one-line reason. */
export type ConciergeCard = Product & { reason?: string };

/** One prior turn sent as conversation context (text only, never product payloads). */
export interface ConciergeHistoryEntry {
  role: 'user' | 'ain';
  text: string;
}

export interface ConciergeResult {
  interactionId: number | null;
  cards: ConciergeCard[];
  /** Ain's short conversational reply ('' when the API predates replies). */
  reply: string;
  /** 0-3 tappable suggestions, phrased as the customer would type them. */
  followUps: string[];
  /** True when the reply is a clarifying question (cards may be empty). */
  needsClarification: boolean;
}

/** The server keeps only the last 8 history entries, each <= 500 chars. */
export const CONCIERGE_HISTORY_LIMIT = 8;
export const CONCIERGE_TEXT_LIMIT = 500;
const FOLLOW_UP_LIMIT = 3;
const FOLLOW_UP_MAX_CHARS = 40;

/**
 * Normalise conversation history to the wire contract: well-formed entries
 * only, trimmed, each text capped at 500 chars, the LAST 8 kept (oldest first).
 */
export function sanitizeHistory(history: readonly unknown[] | null | undefined): ConciergeHistoryEntry[] {
  if (!Array.isArray(history)) {
    return [];
  }
  const out: ConciergeHistoryEntry[] = [];
  for (const raw of history) {
    if (!raw || typeof raw !== 'object') continue;
    const { role, text } = raw as { role?: unknown; text?: unknown };
    if ((role !== 'user' && role !== 'ain') || typeof text !== 'string') continue;
    const t = text.trim().slice(0, CONCIERGE_TEXT_LIMIT);
    if (!t) continue;
    out.push({ role, text: t });
  }
  return out.slice(-CONCIERGE_HISTORY_LIMIT);
}

/** The guided gift-brief inputs. */
export interface GiftBriefInput {
  recipient?: string;
  occasion?: string;
  colours?: string[];
  styles?: string[];
  size?: string;
  budget_min?: number;
  budget_max?: number;
  product_type?: string;
  category_slug?: string;
}

/** The "buy a gift card instead" nudge (references no product). */
export interface GiftCardSuggestion {
  reason: string;
  suggested_denomination: string | null;
  presets: string[];
  currency: string;
  min_denomination: string;
  max_denomination: string;
}

export interface GiftResult {
  interactionId: number | null;
  cards: ConciergeCard[];
  giftCard: GiftCardSuggestion | null;
}

/** The guided outfit-brief inputs. */
export interface OutfitBriefInput {
  occasion?: string;
  styles?: string[];
  colours?: string[];
  budget_min?: number;
  budget_max?: number;
  product_type?: string;
  category_slug?: string;
}

/** One piece of a composed outfit: the product + its role + a reason. */
export interface OutfitPieceCard {
  role: string;
  category_slug: string;
  reason: string;
  product: ConciergeCard;
}

export interface OutfitResult {
  interactionId: number | null;
  rationale: string;
  occasion: string | null;
  pieces: OutfitPieceCard[];
  totalPrice: { amount: number; currency: string } | null;
  giftCard: GiftCardSuggestion | null;
}

interface OutfitResponse {
  interaction_id: number | null;
  rationale?: string;
  occasion?: string | null;
  items?: Array<{ role?: string; category_slug?: string; reason?: string; product?: ConciergeCard }>;
  total_price?: { amount: number; currency: string };
  gift_card_suggestion?: GiftCardSuggestion;
}

export interface VisualSearchResult {
  interactionId: number | null;
  description: string | null;
  cards: ConciergeCard[];
}

interface VisualSearchResponse {
  interaction_id: number | null;
  description?: string | null;
  products?: ConciergeCard[];
}

/** Raw concierge response body (Responder::ok returns it un-enveloped). */
interface ConciergeResponse {
  interaction_id: number | null;
  intent?: unknown;
  products: ConciergeCard[];
  reply?: unknown;
  follow_ups?: unknown;
  needs_clarification?: unknown;
}

interface GiftResponse extends ConciergeResponse {
  gift_card_suggestion?: GiftCardSuggestion;
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

  /**
   * One conversational turn. `history` is the conversation BEFORE this query
   * (oldest first); only the last 8 text entries are sent, and the field is
   * omitted entirely for a fresh conversation (backward compatible).
   */
  async ask(query: string, history: readonly ConciergeHistoryEntry[] = []): Promise<ConciergeResult> {
    const context = sanitizeHistory(history);
    this.analytics.event('ai_concierge_query', { query_length: query.length, history_turns: context.length });

    const body: Record<string, unknown> = {
      query,
      locale: this.locale.current(),
      session_id: this.sessionId(),
      channel: 'WEB',
    };
    if (context.length > 0) {
      body['history'] = context;
    }

    const env = await firstValueFrom(this.http.post<ConciergeResponse>('POST /ai/concierge/style', { body }));

    const data = (env.data ?? {}) as ConciergeResponse;
    const cards = Array.isArray(data.products) ? data.products : [];
    const reply = typeof data.reply === 'string' ? data.reply.trim() : '';
    const followUps = Array.isArray(data.follow_ups)
      ? data.follow_ups
          .filter((f): f is string => typeof f === 'string')
          .map((f) => f.trim().slice(0, FOLLOW_UP_MAX_CHARS))
          .filter((f) => f.length > 0)
          .slice(0, FOLLOW_UP_LIMIT)
      : [];
    const needsClarification = data.needs_clarification === true;
    this.analytics.event('ai_concierge_results', { count: cards.length, clarification: needsClarification });

    return { interactionId: data.interaction_id ?? null, cards, reply, followUps, needsClarification };
  }

  /** Guided gift flow: a structured brief → real gift ideas + optional gift-card nudge. */
  async askGift(brief: GiftBriefInput): Promise<GiftResult> {
    this.analytics.event('ai_gift_started', { occasion: brief.occasion ?? '', has_budget: brief.budget_max != null });
    this.recordEvent('ai_gift_started');

    const env = await firstValueFrom(
      this.http.post<GiftResponse>('POST /ai/concierge/gift', {
        body: {
          ...brief,
          locale: this.locale.current(),
          session_id: this.sessionId(),
          channel: 'WEB',
        },
      }),
    );

    const data = (env.data ?? {}) as GiftResponse;
    const cards = Array.isArray(data.products) ? data.products : [];
    const giftCard = data.gift_card_suggestion ?? null;
    this.analytics.event('ai_gift_results', { count: cards.length, gift_card: !!giftCard });

    return { interactionId: data.interaction_id ?? null, cards, giftCard };
  }

  /** Guided outfit flow: a structured brief → a coordinated multi-piece look. */
  async generateOutfit(brief: OutfitBriefInput): Promise<OutfitResult> {
    this.analytics.event('ai_outfit_started', { occasion: brief.occasion ?? '', has_budget: brief.budget_max != null });
    this.recordEvent('ai_outfit_started');

    const env = await firstValueFrom(
      this.http.post<OutfitResponse>('POST /ai/outfit', {
        body: {
          ...brief,
          locale: this.locale.current(),
          session_id: this.sessionId(),
          channel: 'WEB',
        },
      }),
    );

    const data = (env.data ?? {}) as OutfitResponse;
    const pieces: OutfitPieceCard[] = Array.isArray(data.items)
      ? data.items
          .map((i) => ({
            role: typeof i.role === 'string' ? i.role : 'complement',
            category_slug: typeof i.category_slug === 'string' ? i.category_slug : '',
            reason: typeof i.reason === 'string' ? i.reason : '',
            product: (i.product ?? {}) as ConciergeCard,
          }))
          .filter((p) => p.product && typeof p.product.id === 'number')
      : [];

    this.analytics.event('ai_outfit_results', { count: pieces.length });
    this.recordEvent('ai_outfit_generated', { count: pieces.length });

    return {
      interactionId: data.interaction_id ?? null,
      rationale: typeof data.rationale === 'string' ? data.rationale : '',
      occasion: data.occasion ?? null,
      pieces,
      totalPrice: data.total_price ?? null,
      giftCard: data.gift_card_suggestion ?? null,
    };
  }

  /** Visual search: a query image (data URL) → visually similar real products. */
  async visualSearch(imageDataUrl: string): Promise<VisualSearchResult> {
    this.analytics.event('ai_visual_search_started', {});
    this.recordEvent('ai_visual_search_started');

    const env = await firstValueFrom(
      this.http.post<VisualSearchResponse>('POST /ai/visual-search', {
        body: {
          image: imageDataUrl,
          locale: this.locale.current(),
          session_id: this.sessionId(),
          channel: 'WEB',
        },
      }),
    );

    const data = (env.data ?? {}) as VisualSearchResponse;
    const cards = Array.isArray(data.products) ? data.products : [];
    this.analytics.event('ai_visual_search_results', { count: cards.length });
    this.recordEvent('ai_visual_search_results', { count: cards.length });

    return {
      interactionId: data.interaction_id ?? null,
      description: data.description ?? null,
      cards,
    };
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
