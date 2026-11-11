import { Injectable, PLATFORM_ID, computed, effect, inject, signal, untracked } from '@angular/core';
import { isPlatformBrowser } from '@angular/common';

import { AuthService } from '../../core/auth/auth.service';
import {
  CONCIERGE_HISTORY_LIMIT,
  CONCIERGE_TEXT_LIMIT,
  ConciergeService,
  type ConciergeCard,
  type ConciergeHistoryEntry,
} from './concierge.service';

/** A customer message in the Ask Ain thread. */
export interface UserTurn {
  id: string;
  role: 'user';
  text: string;
}

/** Ain's answer to the preceding user turn (or an error bubble with Retry). */
export interface AinTurn {
  id: string;
  role: 'ain';
  status: 'ok' | 'error';
  reply: string;
  cards: ConciergeCard[];
  followUps: string[];
  needsClarification: boolean;
  interactionId: number | null;
}

export type ChatTurn = UserTurn | AinTurn;

/**
 * Build the wire history from the thread: user text + Ain's reply text only
 * (never product payloads), error bubbles skipped, the last 8 entries kept.
 * A user message Ain never answered (followed by an error bubble) is left out
 * too: if the customer moved on instead of tapping Retry, the server must not
 * merge that abandoned request (e.g. "in red") into the new one.
 */
export function buildHistory(turns: readonly ChatTurn[]): ConciergeHistoryEntry[] {
  const out: ConciergeHistoryEntry[] = [];
  for (let i = 0; i < turns.length; i++) {
    const t = turns[i];
    if (t.role === 'user') {
      const next = turns[i + 1];
      if (next && next.role === 'ain' && next.status === 'error') {
        continue;
      }
      out.push({ role: 'user', text: t.text });
    } else if (t.status === 'ok' && t.reply.trim()) {
      out.push({ role: 'ain', text: t.reply });
    }
  }
  return out.slice(-CONCIERGE_HISTORY_LIMIT);
}

/**
 * The Ask Ain conversation (web). A root service so the thread survives
 * navigating to a product and back (and an in-flight answer still lands
 * while the page is away); mirrored to sessionStorage so it also survives a
 * full page load within the tab. Storage is best-effort: every access is
 * guarded and the thread simply starts fresh when it is unavailable.
 */
@Injectable({ providedIn: 'root' })
export class AskAinThreadService {
  static readonly STORAGE_KEY = 'ain_thread_v1';
  /** Cap on persisted turns so sessionStorage never grows unbounded. */
  static readonly MAX_STORED_TURNS = 40;

  private readonly concierge = inject(ConciergeService);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));

  private readonly _turns = signal<ChatTurn[]>(this.restore());
  private readonly _pending = signal(false);
  /** Bumped by newChat() so a late answer to an abandoned thread is dropped. */
  private generation = 0;

  readonly turns = this._turns.asReadonly();
  readonly pending = this._pending.asReadonly();
  /** A fresh thread shows the greeting + suggestion chips. */
  readonly isFresh = computed(() => this._turns().length === 0 && !this._pending());

  constructor() {
    // The thread (what the customer typed, Ain's replies, personalised cards)
    // must not outlive the account it was had under: on a shared device the
    // next person in the same tab would see it. Clear it when a signed-in
    // customer signs out or a different account takes over. A guest signing
    // in keeps their thread (same person, same tab).
    const auth = inject(AuthService);
    let prevUserId: number | null = null;
    effect(() => {
      const id = auth.currentUser()?.id ?? null;
      if (prevUserId !== null && id !== prevUserId) {
        untracked(() => this.newChat());
      }
      prevUserId = id;
    });
  }

  /** Send a customer message (typed, a suggestion chip, or a follow-up chip). */
  async send(text: string): Promise<void> {
    const q = (text ?? '').trim().slice(0, CONCIERGE_TEXT_LIMIT);
    if (!q || this._pending()) {
      return;
    }
    const before = this._turns();
    const history = buildHistory(before);
    this.commit([...before, { id: this.newId(), role: 'user', text: q }]);
    await this.run(q, history);
  }

  /** Re-send the message whose answer failed (only when the last turn is an error). */
  async retry(): Promise<void> {
    const turns = this._turns();
    const last = turns[turns.length - 1];
    const prev = turns[turns.length - 2];
    if (this._pending() || !last || last.role !== 'ain' || last.status !== 'error' || !prev || prev.role !== 'user') {
      return;
    }
    const withoutError = turns.slice(0, -1);
    this.commit(withoutError);
    await this.run(prev.text, buildHistory(withoutError.slice(0, -1)));
  }

  /** Start over: clears the thread (and any in-flight answer). */
  newChat(): void {
    this.generation++;
    this._pending.set(false);
    this.commit([]);
  }

  private async run(query: string, history: ConciergeHistoryEntry[]): Promise<void> {
    const gen = this.generation;
    this._pending.set(true);
    try {
      const res = await this.concierge.ask(query, history);
      if (gen !== this.generation) return;
      this.commit([
        ...this._turns(),
        {
          id: this.newId(),
          role: 'ain',
          status: 'ok',
          reply: res.reply,
          cards: res.cards,
          followUps: res.followUps,
          needsClarification: res.needsClarification,
          interactionId: res.interactionId,
        },
      ]);
    } catch {
      if (gen !== this.generation) return;
      this.commit([...this._turns(), this.errorTurn()]);
    } finally {
      if (gen === this.generation) {
        this._pending.set(false);
      }
    }
  }

  private errorTurn(): AinTurn {
    return {
      id: this.newId(),
      role: 'ain',
      status: 'error',
      reply: '',
      cards: [],
      followUps: [],
      needsClarification: false,
      interactionId: null,
    };
  }

  private commit(turns: ChatTurn[]): void {
    this._turns.set(turns);
    if (!this.isBrowser) return;
    try {
      if (turns.length === 0) {
        sessionStorage.removeItem(AskAinThreadService.STORAGE_KEY);
      } else {
        sessionStorage.setItem(
          AskAinThreadService.STORAGE_KEY,
          JSON.stringify(turns.slice(-AskAinThreadService.MAX_STORED_TURNS)),
        );
      }
    } catch {
      // Private mode / quota / blocked storage: the in-memory thread still works.
    }
  }

  private restore(): ChatTurn[] {
    if (!this.isBrowser) return [];
    let parsed: unknown;
    try {
      const raw = sessionStorage.getItem(AskAinThreadService.STORAGE_KEY);
      if (!raw) return [];
      parsed = JSON.parse(raw);
    } catch {
      return [];
    }
    if (!Array.isArray(parsed)) return [];

    const turns: ChatTurn[] = [];
    for (const raw of parsed) {
      const t = this.reviveTurn(raw);
      if (t) turns.push(t);
    }
    // A full reload while waiting leaves an unanswered question: offer Retry.
    const last = turns[turns.length - 1];
    if (last && last.role === 'user') {
      turns.push(this.errorTurn());
    }
    return turns;
  }

  private reviveTurn(raw: unknown): ChatTurn | null {
    if (!raw || typeof raw !== 'object') return null;
    const r = raw as Record<string, unknown>;
    const id = typeof r['id'] === 'string' && r['id'] ? r['id'] : this.newId();
    if (r['role'] === 'user') {
      const text = typeof r['text'] === 'string' ? r['text'].trim() : '';
      return text ? { id, role: 'user', text } : null;
    }
    if (r['role'] === 'ain') {
      const cards = Array.isArray(r['cards'])
        ? (r['cards'] as unknown[]).filter(
            (c): c is ConciergeCard => !!c && typeof c === 'object' && typeof (c as { id?: unknown }).id === 'number',
          )
        : [];
      const followUps = Array.isArray(r['followUps'])
        ? (r['followUps'] as unknown[]).filter((f): f is string => typeof f === 'string' && f.trim() !== '')
        : [];
      return {
        id,
        role: 'ain',
        status: r['status'] === 'error' ? 'error' : 'ok',
        reply: typeof r['reply'] === 'string' ? r['reply'] : '',
        cards,
        followUps,
        needsClarification: r['needsClarification'] === true,
        interactionId: typeof r['interactionId'] === 'number' ? r['interactionId'] : null,
      };
    }
    return null;
  }

  private newId(): string {
    return 't-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
  }
}
