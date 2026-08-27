import { Injectable, signal, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';

/**
 * Customer<->vendor order chat domain types + service for apps/web.
 *
 * The v3 chat API is uuid-keyed, Bearer-authenticated (the refresh
 * interceptor attaches the token automatically), text-only, and
 * auto-provisions one conversation per paid order item, there is no
 * get-or-create-by-order endpoint, so the web app only ever LISTS the
 * conversations the server has already created and opens one by uuid.
 *
 * Shapes mirror apps/mobile ChatService + apps/api ChatSerializer:
 *
 *   conversationShape = {
 *     uuid, status, order_reference, unread_count, last_message_at,
 *     last_message_preview, counterparty:{type,name,slug?,logo_url?},
 *     item:{name,image,size,color}, created_at
 *   }
 *   messageShape = {
 *     id, uuid, sender_type, type, content, content_ar, is_flagged,
 *     status, created_at
 *   }
 *
 * Wire envelopes (the controllers return the body at the TOP level, not
 * under `data`; RoutedHttpClient's v3-envelope normaliser passes such a
 * body straight through as `env.data`):
 *
 *   GET  /chat/conversations            -> { conversations, unread_total, pagination }
 *   GET  /chat/conversations/:uuid/messages -> { conversation, messages }
 *   POST /chat/conversations/:uuid/messages -> { message }
 *   POST /chat/conversations/:uuid/read     -> {} (200)
 *   GET  /chat/unread-count             -> { unread_count }
 *
 * A blocked send returns 422 CHAT_MESSAGE_BLOCKED; the HttpErrorResponse
 * propagates so the page can surface the moderation warning.
 */

export type ConversationStatus = 'active' | 'closed' | string;
export type MessageSenderType = 'customer' | 'vendor' | 'system' | string;
export type MessageStatus = 'sent' | 'blocked' | 'redacted' | string;

export interface ChatCounterparty {
  type: string;
  name: string;
  slug: string | null;
  logo_url: string | null;
}

export interface ChatItem {
  name: string;
  image: string | null;
  size: string | null;
  color: string | null;
}

export interface ConversationSummary {
  uuid: string;
  status: ConversationStatus;
  order_reference: string;
  unread_count: number;
  last_message_at: string | null;
  last_message_preview: string | null;
  counterparty: ChatCounterparty;
  item: ChatItem;
  created_at: string;
}

export interface ChatMessage {
  id: number;
  uuid: string;
  sender_type: MessageSenderType;
  type: string;
  content: string | null;
  content_ar: string | null;
  is_flagged: boolean;
  status: MessageStatus;
  created_at: string;
}

export interface ConversationListResult {
  conversations: ConversationSummary[];
  unreadTotal: number;
}

export interface ThreadResult {
  conversation: ConversationSummary | null;
  messages: ChatMessage[];
}

/** Raw wire shapes (top-level body, see class docblock). */
interface RawConversationListBody {
  conversations?: unknown[];
  unread_total?: number;
}
interface RawThreadBody {
  conversation?: unknown;
  messages?: unknown[];
}
interface RawSendBody {
  message?: unknown;
}
interface RawUnreadBody {
  unread_count?: number;
}

@Injectable({ providedIn: 'root' })
export class MessagesService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _isLoading = signal<boolean>(false);
  private readonly _isSending = signal<boolean>(false);
  private readonly _unreadTotal = signal<number>(0);
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();
  readonly isSending: Signal<boolean> = this._isSending.asReadonly();
  /** Inbox-wide unread total, kept fresh by list/unread-count calls. */
  readonly unreadTotal: Signal<number> = this._unreadTotal.asReadonly();

  /** List the customer's order conversations, newest activity first. */
  async listConversations(params: { limit?: number; offset?: number } = {}): Promise<ConversationListResult> {
    this._isLoading.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<RawConversationListBody>('GET /chat/conversations', {
          query: { limit: params.limit ?? 50, offset: params.offset ?? 0 },
        }),
      );
      const body = env.data ?? {};
      const conversations = (body.conversations ?? []).map((c) => this.mapConversation(c));
      const unreadTotal = typeof body.unread_total === 'number' ? body.unread_total : 0;
      this._unreadTotal.set(unreadTotal);
      return { conversations, unreadTotal };
    } finally {
      this._isLoading.set(false);
    }
  }

  /**
   * Fetch a thread. Omit `afterId` for the initial load; pass the highest
   * seen message id to fetch only newer messages (polling).
   */
  async getMessages(uuid: string, afterId?: number, limit = 50): Promise<ThreadResult> {
    const query: Record<string, number> = { limit };
    if (afterId && afterId > 0) {
      query['after_id'] = afterId;
    }
    const env = await firstValueFrom(
      this.http.get<RawThreadBody>('GET /chat/conversations/:uuid/messages', {
        params: { uuid },
        query,
      }),
    );
    const body = env.data ?? {};
    return {
      conversation: body.conversation ? this.mapConversation(body.conversation) : null,
      messages: (body.messages ?? []).map((m) => this.mapMessage(m)),
    };
  }

  /**
   * Send a text message. On 422 CHAT_MESSAGE_BLOCKED the HttpErrorResponse
   * is re-thrown (RoutedHttpClient does not swallow it) so the caller can
   * read `error.error.code` / `.message`.
   */
  async sendMessage(uuid: string, content: string): Promise<ChatMessage> {
    this._isSending.set(true);
    try {
      const env = await firstValueFrom(
        this.http.post<RawSendBody>('POST /chat/conversations/:uuid/messages', {
          params: { uuid },
          body: { content },
        }),
      );
      return this.mapMessage((env.data ?? {}).message);
    } finally {
      this._isSending.set(false);
    }
  }

  /** Mark a conversation read for the customer. Never throws. */
  async markAsRead(uuid: string): Promise<void> {
    try {
      await firstValueFrom(
        this.http.post<unknown>('POST /chat/conversations/:uuid/read', { params: { uuid } }),
      );
    } catch {
      /* best-effort; a failed read-receipt is non-fatal. */
    }
  }

  /** Refresh the inbox-wide unread count (for the nav badge). */
  async refreshUnreadCount(): Promise<number> {
    try {
      const env = await firstValueFrom(
        this.http.get<RawUnreadBody>('GET /chat/unread-count'),
      );
      const n = typeof (env.data ?? {}).unread_count === 'number' ? (env.data as RawUnreadBody).unread_count! : 0;
      this._unreadTotal.set(n);
      return n;
    } catch {
      return this._unreadTotal();
    }
  }

  // ── Mappers ─────────────────────────────────────────────────

  private mapConversation(raw: unknown): ConversationSummary {
    const c = (raw ?? {}) as Record<string, any>;
    const cp = (c['counterparty'] ?? {}) as Record<string, any>;
    const item = (c['item'] ?? {}) as Record<string, any>;
    return {
      uuid: c['uuid'] ?? '',
      status: c['status'] ?? 'active',
      order_reference: c['order_reference'] ?? '',
      unread_count: typeof c['unread_count'] === 'number' ? c['unread_count'] : 0,
      last_message_at: c['last_message_at'] ?? null,
      last_message_preview: c['last_message_preview'] ?? null,
      counterparty: {
        type: cp['type'] ?? '',
        name: cp['name'] ?? '',
        slug: cp['slug'] ?? null,
        logo_url: cp['logo_url'] ?? null,
      },
      item: {
        name: item['name'] ?? '',
        image: item['image'] ?? null,
        size: item['size'] ?? null,
        color: item['color'] ?? null,
      },
      created_at: c['created_at'] ?? '',
    };
  }

  private mapMessage(raw: unknown): ChatMessage {
    const m = (raw ?? {}) as Record<string, any>;
    return {
      id: typeof m['id'] === 'number' ? m['id'] : 0,
      uuid: m['uuid'] ?? '',
      sender_type: m['sender_type'] ?? 'system',
      type: m['type'] ?? 'text',
      content: m['content'] ?? null,
      content_ar: m['content_ar'] ?? null,
      is_flagged: m['is_flagged'] ?? false,
      status: m['status'] ?? 'sent',
      created_at: m['created_at'] ?? '',
    };
  }
}
