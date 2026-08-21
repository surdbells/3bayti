import { Component, OnInit, OnDestroy, ViewChild, ElementRef, NgZone } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

interface ChatCounterparty {
  type: string;
  name: string;
  slug?: string;
  logo_url?: string | null;
}

interface ChatConversation {
  uuid: string;
  status: string;
  order_reference: string;
  unread_count: number;
  last_message_at: string | null;
  last_message_preview: string | null;
  // Nullable: a conversation may lack a resolvable counterparty (e.g. a
  // removed customer) or item, which the template already guards with `?.`
  // and 'Customer'/'?' fallbacks. Declaring them non-null triggered NG8107.
  counterparty: ChatCounterparty | null;
  item: { name: string; image: string | null; size: string | null; color: string | null } | null;
  created_at: string;
}

interface ChatMessage {
  id: number | null;
  uuid: string;
  sender_type: 'customer' | 'vendor' | 'system';
  type: string;
  content: string;
  content_ar: string | null;
  is_flagged: boolean;
  status: string;
  created_at: string;
}

/**
 * Vendor order chat. Lists the vendor's order-scoped conversations with
 * customers and opens a threaded view to reply. New messages arrive by
 * polling the after_id cursor; PII-blocked sends surface the server warning.
 * Replaces the legacy admin->vendor message inbox.
 */
@Component({
  selector: 'app-vendor-messages',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, FormsModule, IconComponent],
  templateUrl: './vendor-messages.component.html',
  styleUrl: './vendor-messages.component.css',
})
export class VendorMessagesComponent implements OnInit, OnDestroy {
  @ViewChild('threadScroll') private threadScroll?: ElementRef<HTMLElement>;

  conversations: ChatConversation[] = [];
  selected: ChatConversation | null = null;
  messages: ChatMessage[] = [];
  draft = '';
  unreadTotal = 0;

  ui = {
    loadingList: false,
    listError: false,
    loadingThread: false,
    sending: false,
  };

  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private lastSeenId = 0;
  private static readonly POLL_MS = 5000;
  private static readonly MAX_LEN = 4000;

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private zone: NgZone,
  ) {}

  ngOnInit(): void {
    this.loadConversations();
  }

  ngOnDestroy(): void {
    this.stopPolling();
  }

  // Conversation list
  loadConversations(): void {
    this.ui.loadingList = true;
    this.ui.listError = false;
    this.adapter.get_v3('GET /vendor/chat/conversations', { query: { limit: 50, offset: 0 } }).subscribe({
      next: (r: any) => {
        this.conversations = (r?.conversations ?? []) as ChatConversation[];
        this.unreadTotal = r?.unread_total ?? 0;
        this.ui.loadingList = false;
      },
      error: (err: any) => {
        this.ui.listError = true;
        this.ui.loadingList = false;
        this.toast.error(apiErrorMessage(err, 'Unable to load your conversations.'));
      },
    });
  }

  // Thread
  openConversation(conversation: ChatConversation): void {
    if (this.selected?.uuid === conversation.uuid) {
      return;
    }
    this.stopPolling();
    this.selected = conversation;
    this.messages = [];
    this.draft = '';
    this.lastSeenId = 0;
    this.ui.loadingThread = true;

    this.adapter
      .get_v3('GET /vendor/chat/conversations/:uuid/messages', {
        params: { uuid: conversation.uuid },
        query: { limit: 50 },
      })
      .subscribe({
        next: (r: any) => {
          this.messages = (r?.messages ?? []) as ChatMessage[];
          this.refreshLastSeen();
          this.ui.loadingThread = false;
          this.markRead(conversation);
          this.scrollToBottomSoon();
          this.startPolling();
        },
        error: (err: any) => {
          this.ui.loadingThread = false;
          this.toast.error(apiErrorMessage(err, 'Unable to load this conversation.'));
        },
      });
  }

  closeThread(): void {
    this.stopPolling();
    this.selected = null;
    this.messages = [];
    this.draft = '';
  }

  // Sending
  send(): void {
    const content = this.draft.trim();
    if (!content || !this.selected || this.ui.sending) {
      return;
    }
    if (content.length > VendorMessagesComponent.MAX_LEN) {
      this.toast.error('Message is too long.');
      return;
    }

    const conversation = this.selected;
    this.ui.sending = true;
    this.adapter
      .post_v3('POST /vendor/chat/conversations/:uuid/messages', { content }, { params: { uuid: conversation.uuid } })
      .subscribe({
        next: (r: any) => {
          if (r?.message) {
            this.messages = [...this.messages, r.message as ChatMessage];
            this.refreshLastSeen();
          }
          conversation.last_message_preview = content;
          conversation.last_message_at = new Date().toISOString();
          this.bumpToTop(conversation);
          this.draft = '';
          this.ui.sending = false;
          this.scrollToBottomSoon();
        },
        error: (err: any) => {
          this.ui.sending = false;
          const apiError = err?.error?.error ?? err?.error;
          if (apiError?.code === 'CHAT_MESSAGE_BLOCKED') {
            this.toast.error(
              apiError.message ||
                'Your message was not sent because it looks like it contains contact details. Please keep the conversation in 3bayti.',
            );
          } else {
            this.toast.error('Could not send your message. Please try again.');
          }
        },
      });
  }

  // Read state
  private markRead(conversation: ChatConversation): void {
    if (conversation.unread_count > 0) {
      conversation.unread_count = 0;
      this.recomputeUnreadTotal();
    }
    this.adapter
      .post_v3('POST /vendor/chat/conversations/:uuid/read', {}, { params: { uuid: conversation.uuid } })
      .subscribe({ next: () => {}, error: () => {} });
  }

  // Polling
  private startPolling(): void {
    this.stopPolling();
    this.zone.runOutsideAngular(() => {
      this.pollTimer = setInterval(() => this.pollNew(), VendorMessagesComponent.POLL_MS);
    });
  }

  private stopPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private pollNew(): void {
    const conversation = this.selected;
    if (!conversation) {
      return;
    }
    this.adapter
      .get_v3('GET /vendor/chat/conversations/:uuid/messages', {
        params: { uuid: conversation.uuid },
        query: { after_id: this.lastSeenId, limit: 50 },
      })
      .subscribe({
        next: (r: any) => {
          const incoming = (r?.messages ?? []) as ChatMessage[];
          if (incoming.length === 0) {
            return;
          }
          this.zone.run(() => {
            this.messages = [...this.messages, ...incoming];
            this.refreshLastSeen();
            conversation.last_message_preview = incoming[incoming.length - 1].content;
            conversation.last_message_at = incoming[incoming.length - 1].created_at;
            this.markRead(conversation);
            this.scrollToBottomSoon();
          });
        },
        error: () => {
          /* polling is best-effort; ignore transient errors */
        },
      });
  }

  // Helpers
  isMine(message: ChatMessage): boolean {
    return message.sender_type === 'vendor';
  }

  isSystem(message: ChatMessage): boolean {
    return message.sender_type === 'system';
  }

  trackByUuid(_: number, item: { uuid: string }): string {
    return item.uuid;
  }

  formatTime(iso: string | null): string {
    if (!iso) {
      return '';
    }
    const d = new Date(iso);
    const now = new Date();
    const sameDay = d.toDateString() === now.toDateString();
    return sameDay
      ? d.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })
      : d.toLocaleDateString([], { day: '2-digit', month: 'short' });
  }

  goBack(): void {
    this.router.navigate(['/vendor/dashboard']);
  }

  private refreshLastSeen(): void {
    for (const m of this.messages) {
      if (typeof m.id === 'number' && m.id > this.lastSeenId) {
        this.lastSeenId = m.id;
      }
    }
  }

  private recomputeUnreadTotal(): void {
    this.unreadTotal = this.conversations.reduce((sum, c) => sum + (c.unread_count || 0), 0);
  }

  private bumpToTop(conversation: ChatConversation): void {
    this.conversations = [conversation, ...this.conversations.filter((c) => c.uuid !== conversation.uuid)];
  }

  private scrollToBottomSoon(): void {
    setTimeout(() => {
      const el = this.threadScroll?.nativeElement;
      if (el) {
        el.scrollTop = el.scrollHeight;
      }
    }, 50);
  }
}
