import { DestroyRef, Injectable, inject, signal } from '@angular/core';
import { firstValueFrom } from 'rxjs';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { SessionStore } from '../../core/services/session-store.service';
import { I18nService } from '../../i18n.service';

/** Money block from ProductSerializer (AED amounts). */
export interface AinPrice {
  amount: number;
  currency: string;
}

/** Vendor embed on a concierge card. */
export interface AinVendor {
  slug: string;
  name: string;
  legacy_id: number | null;
  id: number;
}

/**
 * One product Ain recommends: the v3 ProductSerializer::listShape card plus the
 * concierge's one-line `reason`. This endpoint is NOT run through the mobile
 * legacy response transform, so the fields are the raw v3 shape.
 */
export interface AinCard {
  id: number;
  name: string;
  price: AinPrice | null;
  sale_price: AinPrice | null;
  primary_image: { url: string } | null;
  vendor: AinVendor | null;
  in_stock: boolean;
  reason?: string;
}

/** The customer's message. */
export interface AinUserTurn {
  id: number;
  role: 'user';
  text: string;
}

/** Ain's answer: reply text + (maybe) product cards + follow-up chips. */
export interface AinReplyTurn {
  id: number;
  role: 'ain';
  text: string;
  products: AinCard[];
  followUps: string[];
  needsClarification: boolean;
  /** Interaction id of this reply (threads the analytics events per turn). */
  interactionId: number | null;
}

/** A failed request: rendered as an Ain-style error bubble with Retry. */
export interface AinErrorTurn {
  id: number;
  role: 'error';
  /** The query to resend on Retry. */
  query: string;
}

export type AinTurn = AinUserTurn | AinReplyTurn | AinErrorTurn;

/** One history entry sent to the concierge (text only, never product payloads). */
export interface AinHistoryEntry {
  role: 'user' | 'ain';
  text: string;
}

/** Per-request context the page supplies (locale, analytics session, auth). */
export interface AinAskContext {
  locale: string;
  sessionId: string;
  authToken?: string;
}

/** The server keeps (and we send) only the last 8 conversation entries. */
export const AIN_HISTORY_LIMIT = 8;
/** Per-entry cap, matching the server's truncation. */
export const AIN_TEXT_LIMIT = 500;
const FOLLOW_UP_LIMIT = 3;
const FOLLOW_UP_CHARS = 40;
const REPLY_CHARS = 600;

/** Strip markup + URLs from model text before it is shown (defence in depth; the server does too). */
function cleanText(value: unknown, max: number): string {
  if (typeof value !== 'string') {
    return '';
  }
  return value
    .replace(/<[^>]*>/g, '')
    .replace(/\bhttps?:\/\/\S+/gi, '')
    .replace(/\bwww\.\S+/gi, '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, max);
}

/**
 * The Ask Ain conversation thread. A ROOT service so the thread outlives the
 * page: navigating to a product and back (or the page being re-created) keeps
 * the conversation. Memory only, by design: nothing is written to Preferences,
 * so a cold start begins a fresh chat.
 *
 * It also owns the in-flight request, so an answer that lands while the
 * customer is on a product page is still added to the thread.
 *
 * The thread belongs to ONE customer: it is cleared when the session ends
 * (logout / deletion / server rejection) and when a different account is
 * signed in, so a second customer on the same device never sees, or sends as
 * `history`, the previous customer's conversation. Signing in mid-chat from
 * signed-out (e.g. "add to closet" -> login -> back) keeps the thread.
 */
@Injectable({ providedIn: 'root' })
export class AskAinThreadService {
  private network = inject(MobileNetworkAdapter);
  private i18n = inject(I18nService);
  private session = inject(SessionStore);

  private nextId = 1;
  /** Bumped on reset, so an answer to a request from a previous thread is dropped. */
  private epoch = 0;
  /** User id of the signed-in customer this thread belongs to (null = none seen yet). */
  private owner: number | null = null;

  /** The conversation, oldest first. */
  readonly turns = signal<AinTurn[]>([]);
  /** True while Ain is answering (typing indicator). */
  readonly pending = signal(false);

  /**
   * Key (navigation id + query) of the last deep link (?q=) already turned
   * into a message, so a page re-created on the SAME navigation (history
   * entry) does not send it twice, while a fresh navigation with the same
   * query starts a new conversation.
   */
  consumedDeepLink: string | null = null;

  constructor() {
    const sub = this.session.sessionEnded$.subscribe(() => this.reset());
    inject(DestroyRef).onDestroy(() => sub.unsubscribe());
  }

  get isEmpty(): boolean {
    return this.turns().length === 0;
  }

  /** Start over: a fresh thread (the greeting state shows again). */
  reset(): void {
    this.turns.set([]);
    this.pending.set(false);
    this.owner = null;
    this.epoch++;
  }

  /**
   * Clear the thread when it belongs to a different signed-in customer than
   * the current session (an account switch the logout event did not cover).
   */
  ensureOwner(): void {
    const raw = this.session.snapshot()?.id;
    const id = typeof raw === 'number' && raw > 0 ? raw : null;
    if (this.owner !== null && id !== this.owner) {
      this.reset();
    }
    if (id !== null) {
      this.owner = id;
    }
  }

  /**
   * The conversation BEFORE the next query, oldest first: user text + Ain reply
   * text only (no product payloads, no error bubbles, and no customer message
   * that never got an answer — same rule as web), the last 8 entries, each
   * capped at 500 chars.
   */
  history(turns: readonly AinTurn[] = this.turns()): AinHistoryEntry[] {
    const entries: AinHistoryEntry[] = [];
    for (let i = 0; i < turns.length; i++) {
      const turn = turns[i];
      if (turn.role === 'error') {
        continue;
      }
      if (turn.role === 'user' && turns[i + 1]?.role === 'error') {
        continue;
      }
      const text = (turn.text ?? '').trim().slice(0, AIN_TEXT_LIMIT);
      if (text) {
        entries.push({ role: turn.role, text });
      }
    }
    return entries.slice(-AIN_HISTORY_LIMIT);
  }

  /**
   * Send a new customer message. The user bubble and the pending flag appear
   * SYNCHRONOUSLY (before any await), so a second tap while the context is
   * still resolving is refused visibly instead of racing. `ctx` may be a
   * promise. Resolves true once Ain's reply (or the error bubble) is in the
   * thread, false when the message was refused.
   */
  async send(text: string, ctx: AinAskContext | Promise<AinAskContext>): Promise<boolean> {
    const query = (text ?? '').trim().slice(0, AIN_TEXT_LIMIT);
    if (!query || this.pending()) {
      return false;
    }
    this.ensureOwner();
    const history = this.history();
    this.append({ id: this.nextId++, role: 'user', text: query });
    await this.request(query, history, ctx);
    return true;
  }

  /**
   * Retry a failed turn: drop the error bubble and resend its query (no
   * duplicate user bubble). Only the NEWEST turn can be retried: retrying an
   * older error would append its answer after later messages, misordering the
   * conversation and the history sent with every later turn.
   */
  async retry(errorTurnId: number, ctx: AinAskContext | Promise<AinAskContext>): Promise<boolean> {
    if (this.pending()) {
      return false;
    }
    this.ensureOwner();
    const turns = this.turns();
    const index = turns.length - 1;
    const failed = turns[index];
    if (!failed || failed.id !== errorTurnId || failed.role !== 'error') {
      return false;
    }
    const remaining = turns.slice(0, index);
    this.turns.set(remaining);
    // History is everything before the customer's (still shown) message.
    const last = remaining[remaining.length - 1];
    const history = this.history(last?.role === 'user' ? remaining.slice(0, -1) : remaining);
    await this.request(failed.query, history, ctx);
    return true;
  }

  /** Whether `turnId` is the newest turn (only that error bubble offers Retry). */
  isLast(turnId: number): boolean {
    const turns = this.turns();
    return turns.length > 0 && turns[turns.length - 1].id === turnId;
  }

  private async request(
    query: string,
    history: AinHistoryEntry[],
    ctxOrPromise: AinAskContext | Promise<AinAskContext>,
  ): Promise<void> {
    this.pending.set(true);
    const epoch = this.epoch;

    let res: any = null;
    try {
      const ctx = await ctxOrPromise;
      // Reset while the context was resolving: send nothing.
      if (epoch !== this.epoch) {
        return;
      }
      const body: Record<string, unknown> = {
        query,
        locale: ctx.locale,
        session_id: ctx.sessionId,
        channel: 'MOBILE',
      };
      if (history.length) {
        body['history'] = history;
      }
      const opts = ctx.authToken ? { authToken: ctx.authToken } : {};
      res = await firstValueFrom(this.network.post_v3('POST /ai/concierge/style', body, opts));
    } catch {
      res = null;
    }
    // A reset while the request was in flight: drop the stale answer.
    if (epoch !== this.epoch) {
      return;
    }
    this.pending.set(false);

    if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
      this.append(this.toReply(res.data));
    } else {
      this.append({ id: this.nextId++, role: 'error', query });
    }
  }

  private toReply(data: any): AinReplyTurn {
    const products: AinCard[] = Array.isArray(data.products) ? (data.products as AinCard[]) : [];
    const followUps: string[] = Array.isArray(data.follow_ups)
      ? data.follow_ups
          .map((f: unknown) => cleanText(f, FOLLOW_UP_CHARS))
          .filter((f: string) => f !== '')
          .slice(0, FOLLOW_UP_LIMIT)
      : [];
    let text = cleanText(data.reply, REPLY_CHARS);
    if (!text) {
      // Older API without `reply`: a localized line describing the result.
      text = this.i18n.t(products.length ? 'ask_ain_results_intro' : 'ask_ain_no_results');
    }
    return {
      id: this.nextId++,
      role: 'ain',
      text,
      products,
      followUps,
      needsClarification: data.needs_clarification === true,
      interactionId: typeof data.interaction_id === 'number' ? data.interaction_id : null,
    };
  }

  private append(turn: AinTurn): void {
    this.turns.update((turns) => [...turns, turn]);
  }
}
