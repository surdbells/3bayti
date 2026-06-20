import { Injectable, signal, Signal, inject } from '@angular/core';
import { firstValueFrom } from 'rxjs';
import { RoutedHttpClient } from '../../core/http/routed-http-client';

/**
 * Customer support-ticket domain types — mirror apps/api TicketSerializer.
 *
 * The API is owner-scoped (/v3/me/tickets, resolved from the Bearer
 * token). Statuses + priorities are lowercase on the wire:
 *   status:   open | pending | resolved | closed
 *   priority: low | medium | high | urgent
 *
 * vendor_id is nullable — general (non-store) tickets are allowed.
 * Timestamps are ISO 8601 (ATOM).
 */
export type TicketStatus = 'open' | 'pending' | 'resolved' | 'closed';
export type TicketPriority = 'low' | 'medium' | 'high' | 'urgent';

/** listItemShape from TicketSerializer (GET /me/tickets rows). */
export interface TicketListItem {
  id: number;
  subject: string;
  /** body truncated to 140 chars + ellipsis. */
  summary: string;
  status: TicketStatus;
  priority: TicketPriority;
  vendor_id: number | null;
  message_count: number;
  created_at: string;
  updated_at: string;
}

/** messageShape from TicketSerializer (thread entries). */
export interface TicketMessage {
  id: number;
  body: string;
  author_name: string;
  /** true = support/admin reply (rendered on the left). */
  is_admin_reply: boolean;
  created_at: string;
}

/** detailShape from TicketSerializer (GET /me/tickets/:id). */
export interface TicketDetail {
  id: number;
  subject: string;
  body: string;
  status: TicketStatus;
  priority: TicketPriority;
  vendor_id: number | null;
  messages: TicketMessage[];
  created_at: string;
  updated_at: string;
}

/**
 * Create payload. CreateMyTicketController accepts { subject, message,
 * vendor_id? }. There is NO priority/category input on create — the
 * server defaults priority to 'medium'. vendor_id is only sent when a
 * store is chosen; omit it for a general support ticket.
 */
export interface CreateTicketInput {
  subject: string;
  message: string;
  vendor_id?: number;
}

/** Pagination meta echoed by GET /me/tickets. */
export interface TicketListMeta {
  total: number;
  limit: number;
  offset: number;
}

export interface TicketListResult {
  tickets: TicketListItem[];
  meta: TicketListMeta;
}

/** i18n key map for ticket status badges. */
export const TICKET_STATUS_LABELS: Record<TicketStatus, string> = {
  open: 'account.support.status.open',
  pending: 'account.support.status.pending',
  resolved: 'account.support.status.resolved',
  closed: 'account.support.status.closed',
};

/** i18n key map for ticket priority labels. */
export const TICKET_PRIORITY_LABELS: Record<TicketPriority, string> = {
  low: 'account.support.priority.low',
  medium: 'account.support.priority.medium',
  high: 'account.support.priority.high',
  urgent: 'account.support.priority.urgent',
};

/**
 * SupportService — customer-facing support tickets.
 *
 * Owner-scoped reads + writes against /v3/me/tickets, routed through
 * RoutedHttpClient (bearer attached by the auth interceptor). Modelled
 * on ProfileService: signal-based loading flags + firstValueFrom around
 * the route-key calls.
 *
 * Endpoints:
 *   GET   /me/tickets[?status=&limit=&offset=]  → { data, meta }
 *   GET   /me/tickets/:id                        → TicketDetail
 *   POST  /me/tickets                            → TicketDetail (201)
 *   GET   /me/tickets/:id/messages               → TicketMessage[]
 *   POST  /me/tickets/:id/messages               → TicketMessage (201)
 */
@Injectable({ providedIn: 'root' })
export class SupportService {
  private readonly http = inject(RoutedHttpClient);

  private readonly _isLoading = signal<boolean>(false);
  private readonly _isSaving = signal<boolean>(false);
  readonly isLoading: Signal<boolean> = this._isLoading.asReadonly();
  readonly isSaving: Signal<boolean> = this._isSaving.asReadonly();

  /** List the current user's tickets, newest first (server order). */
  async listTickets(params: { status?: TicketStatus; limit?: number; offset?: number } = {}): Promise<TicketListResult> {
    this._isLoading.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<TicketListItem[]>('GET /me/tickets', {
          query: {
            status: params.status,
            limit: params.limit ?? 20,
            offset: params.offset ?? 0,
          },
        }),
      );
      const meta = (env.meta ?? {}) as Partial<TicketListMeta>;
      return {
        tickets: env.data ?? [],
        meta: {
          total: meta.total ?? (env.data?.length ?? 0),
          limit: meta.limit ?? params.limit ?? 20,
          offset: meta.offset ?? params.offset ?? 0,
        },
      };
    } finally {
      this._isLoading.set(false);
    }
  }

  /** Fetch a single ticket with its full message thread. */
  async getTicket(id: number): Promise<TicketDetail> {
    this._isLoading.set(true);
    try {
      const env = await firstValueFrom(
        this.http.get<TicketDetail>('GET /me/tickets/:id', {
          params: { id: String(id) },
        }),
      );
      return env.data;
    } finally {
      this._isLoading.set(false);
    }
  }

  /** Create a new ticket. Returns the created ticket (with its thread). */
  async createTicket(input: CreateTicketInput): Promise<TicketDetail> {
    this._isSaving.set(true);
    try {
      const body: CreateTicketInput = {
        subject: input.subject,
        message: input.message,
      };
      if (input.vendor_id !== undefined && input.vendor_id > 0) {
        body.vendor_id = input.vendor_id;
      }
      const env = await firstValueFrom(
        this.http.post<TicketDetail>('POST /me/tickets', { body }),
      );
      return env.data;
    } finally {
      this._isSaving.set(false);
    }
  }

  /** Post a reply to a ticket. Re-opens a resolved/closed ticket. */
  async replyToTicket(id: number, message: string): Promise<TicketMessage> {
    this._isSaving.set(true);
    try {
      const env = await firstValueFrom(
        this.http.post<TicketMessage>('POST /me/tickets/:id/messages', {
          params: { id: String(id) },
          body: { message },
        }),
      );
      return env.data;
    } finally {
      this._isSaving.set(false);
    }
  }
}
