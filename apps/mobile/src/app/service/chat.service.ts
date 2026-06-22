import { Injectable } from '@angular/core';
import { HttpClient, HttpHeaders, HttpParams, HttpErrorResponse } from '@angular/common/http';
import { Observable, BehaviorSubject, from, throwError } from 'rxjs';
import { map, switchMap, catchError, tap } from 'rxjs/operators';
import { Preferences } from '@capacitor/preferences';

import { ChatMessage, ChatConversationSummary } from '../models/chat.models';

const V3_BASE = 'https://api-v3.3bayti.ae';
const CUSTOMER_BASE = '/v3/chat';
const VENDOR_BASE = '/v3/vendor/chat';

export type ChatRole = 'customer' | 'vendor';

/**
 * Order-scoped customer<->vendor chat, wired to the v3 API.
 *
 * v3 is uuid-keyed, Bearer-authenticated, text-only, and auto-provisions a
 * conversation per order item when the order is paid (there is no
 * get-or-create-by-order endpoint). New messages are fetched with an
 * `after_id` cursor. PII-bearing sends are rejected 422 CHAT_MESSAGE_BLOCKED
 * by the server; the HttpErrorResponse propagates so the page can surface the
 * warning.
 */
@Injectable({ providedIn: 'root' })
export class ChatService {
  private messages$ = new BehaviorSubject<ChatMessage[]>([]);
  private unreadCount$ = new BehaviorSubject<number>(0);

  constructor(private http: HttpClient) {}

  // ── Conversation list ───────────────────────────────────────

  listConversations(role: ChatRole = 'customer', limit = 50, offset = 0): Observable<ChatConversationSummary[]> {
    return this.authedGet(`${this.base(role)}/conversations`, { limit, offset }).pipe(
      tap((r: any) => {
        if (typeof r?.unread_total === 'number') {
          this.unreadCount$.next(r.unread_total);
        }
      }),
      map((r: any) => ((r?.conversations ?? []) as any[]).map((c) => this.mapConversation(c))),
      catchError(() => from([[] as ChatConversationSummary[]])),
    );
  }

  // ── Messages ────────────────────────────────────────────────

  /**
   * Fetch messages for a conversation. Omit `afterId` for the initial load
   * (replaces the buffer); pass the highest seen id to fetch only newer
   * messages (appended). Returns the conversation meta alongside.
   */
  getMessages(
    uuid: string,
    role: ChatRole = 'customer',
    afterId?: number,
    limit = 50,
  ): Observable<{ messages: ChatMessage[]; conversation: ChatConversationSummary | null }> {
    const query: Record<string, number> = { limit };
    if (afterId && afterId > 0) {
      query['after_id'] = afterId;
    }
    return this.authedGet(`${this.base(role)}/conversations/${uuid}/messages`, query).pipe(
      map((r: any) => {
        const messages = ((r?.messages ?? []) as any[]).map((m) => this.mapMessage(m));
        const conversation = r?.conversation ? this.mapConversation(r.conversation) : null;
        if (!afterId) {
          this.messages$.next(messages);
        } else if (messages.length) {
          this.messages$.next([...this.messages$.value, ...messages]);
        }
        return { messages, conversation };
      }),
    );
  }

  /**
   * Send a text message. On a 422 CHAT_MESSAGE_BLOCKED the HttpErrorResponse
   * is re-thrown so the caller can read `error.error.code` / `.message`.
   */
  sendMessage(uuid: string, content: string, role: ChatRole = 'customer'): Observable<ChatMessage> {
    return this.authedPost(`${this.base(role)}/conversations/${uuid}/messages`, { content }).pipe(
      map((r: any) => {
        const message = this.mapMessage(r?.message);
        this.messages$.next([...this.messages$.value, message]);
        return message;
      }),
      catchError((err: HttpErrorResponse) => throwError(() => err)),
    );
  }

  markAsRead(uuid: string, role: ChatRole = 'customer'): Observable<boolean> {
    return this.authedPost(`${this.base(role)}/conversations/${uuid}/read`, {}).pipe(
      map(() => true),
      catchError(() => from([false])),
    );
  }

  getUnreadCount(role: ChatRole = 'customer'): Observable<number> {
    return this.authedGet(`${this.base(role)}/unread-count`, {}).pipe(
      map((r: any) => (typeof r?.unread_count === 'number' ? r.unread_count : 0)),
      tap((n) => this.unreadCount$.next(n)),
      catchError(() => from([0])),
    );
  }

  // ── Reactive getters ────────────────────────────────────────

  get messageList$(): Observable<ChatMessage[]> {
    return this.messages$.asObservable();
  }

  get unread$(): Observable<number> {
    return this.unreadCount$.asObservable();
  }

  // ── State ───────────────────────────────────────────────────

  clearChat(): void {
    this.messages$.next([]);
  }

  resetState(): void {
    this.clearChat();
    this.unreadCount$.next(0);
  }

  // ── HTTP plumbing ───────────────────────────────────────────

  private base(role: ChatRole): string {
    return role === 'vendor' ? VENDOR_BASE : CUSTOMER_BASE;
  }

  private authedGet(path: string, query: Record<string, number>): Observable<unknown> {
    let params = new HttpParams();
    for (const [k, v] of Object.entries(query)) {
      params = params.set(k, String(v));
    }
    return from(this.authHeaders()).pipe(
      switchMap((headers) => this.http.get<unknown>(`${V3_BASE}${path}`, { headers, params })),
    );
  }

  private authedPost(path: string, body: unknown): Observable<unknown> {
    return from(this.authHeaders()).pipe(
      switchMap((headers) => this.http.post<unknown>(`${V3_BASE}${path}`, body, { headers })),
    );
  }

  private async authHeaders(): Promise<HttpHeaders> {
    let token = '';
    try {
      const ret = await Preferences.get({ key: 'user' });
      if (ret.value) {
        token = JSON.parse(ret.value)?.token || '';
      }
    } catch {
      token = '';
    }
    return new HttpHeaders(token ? { Authorization: `Bearer ${token}` } : {});
  }

  private mapConversation(c: any): ChatConversationSummary {
    return {
      uuid: c?.uuid ?? '',
      status: c?.status ?? 'active',
      order_reference: c?.order_reference ?? '',
      unread_count: typeof c?.unread_count === 'number' ? c.unread_count : 0,
      last_message_at: c?.last_message_at ?? null,
      preview: c?.last_message_preview ?? c?.preview ?? null,
      counterparty: {
        type: c?.counterparty?.type ?? '',
        name: c?.counterparty?.name ?? '',
        slug: c?.counterparty?.slug,
        logo_url: c?.counterparty?.logo_url ?? null,
      },
      item: {
        name: c?.item?.name ?? '',
        image: c?.item?.image ?? null,
        size: c?.item?.size ?? null,
        color: c?.item?.color ?? null,
      },
      created_at: c?.created_at ?? '',
    };
  }

  private mapMessage(m: any): ChatMessage {
    const rawStatus = m?.status ?? 'sent';
    return {
      message_id: typeof m?.id === 'number' ? m.id : 0,
      uuid: m?.uuid ?? '',
      conversation_id: 0,
      sender_id: 0,
      sender_type: m?.sender_type ?? 'system',
      message_type: m?.type ?? 'text',
      content: m?.content ?? null,
      content_ar: m?.content_ar ?? null,
      prompt_id: null,
      prompt_category: null,
      has_attachments: 0,
      attachments: [],
      status: rawStatus === 'blocked' ? 'failed' : rawStatus === 'redacted' ? 'sent' : rawStatus,
      delivered_at: null,
      read_at: null,
      is_flagged: m?.is_flagged ?? false,
      created_at: m?.created_at ?? '',
      sender_name: '',
    };
  }
}
