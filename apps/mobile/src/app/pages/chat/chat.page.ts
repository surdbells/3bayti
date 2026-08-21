import {
  Component,
  OnInit,
  OnDestroy,
  ViewChild,
  ElementRef,
  ChangeDetectorRef,
  ChangeDetectionStrategy,
  AfterViewInit,
  Inject,
  LOCALE_ID,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import {
  IonContent,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButtons,
  IonButton,
  IonFooter,
  IonSpinner,
  NavController,
  Platform,
} from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';
import { Haptics, ImpactStyle } from '@capacitor/haptics';

import { ChatService, ChatRole } from '../../service/chat.service';
import { apiErrorMessage } from '../../core/http/api-error';
import { ChatMessage, ChatConversationSummary } from '../../models/chat.models';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';

/**
 * Order chat thread (v3). Opened with `?uuid=<conversation>` and an optional
 * `?role=vendor`. The conversation is auto-provisioned server-side; the first
 * system message carries the full order details, so there's no separate order
 * modal. New messages are polled with the after_id cursor. PII-bearing sends
 * are rejected 422 by the server and surfaced as a warning.
 */
@Component({
  selector: 'app-chat',
  templateUrl: './chat.page.html',
  styleUrls: ['./chat.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButtons,
    IonButton,
    IonFooter,
    IonSpinner,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class ChatPage implements OnInit, OnDestroy, AfterViewInit {
  @ViewChild(IonContent, { static: false }) chatContent!: IonContent;
  @ViewChild('messageInput', { static: false }) messageInput!: ElementRef<HTMLTextAreaElement>;

  role: ChatRole = 'customer';
  currentLang = 'en';

  conversation: ChatConversationSummary | null = null;
  messages: ChatMessage[] = [];

  messageText = '';
  isLoading = true;
  isSending = false;

  private uuid = '';
  private lastSeenId = 0;
  private pollTimer: ReturnType<typeof setInterval> | null = null;
  private subscriptions: Subscription[] = [];
  private static readonly POLL_MS = 5000;
  private static readonly MAX_LEN = 4000;

  constructor(
    private chatService: ChatService,
    private route: ActivatedRoute,
    private router: Router,
    private nav: NavController,
    private platform: Platform,
    private cdr: ChangeDetectorRef,
    private toast: AxNotificationService,
    private i18n: I18nService,
    @Inject(LOCALE_ID) private localeId: string,
  ) {}

  ngOnInit(): void {
    this.initializeLanguage();
    this.route.queryParams.subscribe((params) => {
      this.uuid = String(params['uuid'] ?? '');
      this.role = params['role'] === 'vendor' ? 'vendor' : 'customer';
      if (this.uuid) {
        this.initialize();
      } else {
        this.router.navigate(['/chat-vendors']);
      }
    });
  }

  ngAfterViewInit(): void {
    setTimeout(() => this.focusInput(), 500);
  }

  ngOnDestroy(): void {
    this.stopPolling();
    this.subscriptions.forEach((s) => s.unsubscribe());
    this.chatService.clearChat();
  }

  get canSend(): boolean {
    return this.conversation?.status !== 'closed';
  }

  private async initializeLanguage(): Promise<void> {
    const stored = await Preferences.get({ key: 'language' });
    this.currentLang = stored.value || (this.localeId.startsWith('ar') ? 'ar' : 'en');
  }

  private async initialize(): Promise<void> {
    const userData = await Preferences.get({ key: 'user' });
    if (!userData.value) {
      this.router.navigate(['/login']);
      return;
    }
    this.loadMessages();
  }

  private loadMessages(): void {
    this.isLoading = true;
    this.cdr.markForCheck();

    const sub = this.chatService.getMessages(this.uuid, this.role).subscribe({
      next: ({ messages, conversation }) => {
        this.messages = messages;
        if (conversation) {
          this.conversation = conversation;
        }
        this.refreshLastSeen();
        this.isLoading = false;
        this.cdr.markForCheck();
        setTimeout(() => this.scrollToBottom(), 100);
        this.markRead();
        this.startPolling();
      },
      error: () => {
        this.isLoading = false;
        this.cdr.markForCheck();
      },
    });
    this.subscriptions.push(sub);
  }

  // ── Sending ─────────────────────────────────────────────────

  sendMessage(): void {
    const content = this.messageText.trim();
    if (!this.uuid || !content || this.isSending || !this.canSend) {
      return;
    }
    if (content.length > ChatPage.MAX_LEN) {
      this.toast.error(this.i18n.t('text_message_too_long'), { position: 'top-center' });
      return;
    }

    this.messageText = '';
    this.resetTextarea();
    this.isSending = true;

    const tempUuid = 'temp-' + Date.now();
    this.messages = [...this.messages, this.createTempMessage(tempUuid, content)];
    this.scrollToBottom();
    this.triggerHaptic();
    this.cdr.markForCheck();

    const sub = this.chatService.sendMessage(this.uuid, content, this.role).subscribe({
      next: (message) => {
        this.messages = this.messages.map((m) => (m.uuid === tempUuid ? message : m));
        this.refreshLastSeen();
        if (this.conversation) {
          this.conversation.preview = content;
          this.conversation.last_message_at = message.created_at;
        }
        this.isSending = false;
        this.scrollToBottom();
        this.cdr.markForCheck();
      },
      error: (err) => {
        this.messages = this.messages.filter((m) => m.uuid !== tempUuid);
        this.isSending = false;
        const apiError = err?.error?.error ?? err?.error;
        if (apiError?.code === 'CHAT_MESSAGE_BLOCKED') {
          this.toast.error(apiError.message || this.i18n.t('text_chat_blocked'), { position: 'top-center' });
        } else {
          this.toast.error(apiErrorMessage(err, this.i18n.t('text_message_send_failed')), { position: 'top-center' });
        }
        this.messageText = content;
        this.cdr.markForCheck();
      },
    });
    this.subscriptions.push(sub);
  }

  private createTempMessage(tempUuid: string, content: string): ChatMessage {
    return {
      message_id: 0,
      uuid: tempUuid,
      conversation_id: 0,
      sender_id: 0,
      sender_type: this.role,
      message_type: 'text',
      content,
      content_ar: null,
      prompt_id: null,
      prompt_category: null,
      has_attachments: 0,
      attachments: [],
      status: 'sending',
      delivered_at: null,
      read_at: null,
      is_flagged: false,
      created_at: new Date().toISOString(),
      sender_name: '',
    };
  }

  // ── Read + polling ──────────────────────────────────────────

  private refreshLastSeen(): void {
    for (const m of this.messages) {
      if (typeof m.message_id === 'number' && m.message_id > this.lastSeenId) {
        this.lastSeenId = m.message_id;
      }
    }
  }

  private markRead(): void {
    if (this.conversation && this.conversation.unread_count > 0) {
      this.conversation.unread_count = 0;
    }
    const sub = this.chatService.markAsRead(this.uuid, this.role).subscribe();
    this.subscriptions.push(sub);
  }

  private startPolling(): void {
    this.stopPolling();
    this.pollTimer = setInterval(() => this.pollNew(), ChatPage.POLL_MS);
  }

  private stopPolling(): void {
    if (this.pollTimer) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private pollNew(): void {
    if (!this.uuid) {
      return;
    }
    const sub = this.chatService.getMessages(this.uuid, this.role, this.lastSeenId).subscribe({
      next: ({ messages }) => {
        if (!messages.length) {
          return;
        }
        this.messages = [...this.messages, ...messages];
        this.refreshLastSeen();
        const last = messages[messages.length - 1];
        if (this.conversation) {
          this.conversation.preview = last.content;
          this.conversation.last_message_at = last.created_at;
        }
        this.markRead();
        this.scrollToBottom();
        this.cdr.markForCheck();
      },
      error: () => {
        /* best-effort */
      },
    });
    this.subscriptions.push(sub);
  }

  // ── View helpers ────────────────────────────────────────────

  isOwnMessage(message: ChatMessage): boolean {
    return message.sender_type === this.role;
  }

  isSystem(message: ChatMessage): boolean {
    return message.sender_type === 'system';
  }

  isBlocked(message: ChatMessage): boolean {
    return message.status === 'failed';
  }

  trackByMessage(_: number, m: ChatMessage): string {
    return m.uuid || String(m.message_id);
  }

  shouldShowDateSeparator(message: ChatMessage, index: number): boolean {
    if (index === 0) {
      return true;
    }
    const prev = this.messages[index - 1];
    if (!prev) {
      return true;
    }
    return new Date(prev.created_at).toDateString() !== new Date(message.created_at).toDateString();
  }

  formatDateSeparator(dateString: string): string {
    const date = new Date(dateString);
    const today = new Date();
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);
    if (date.toDateString() === today.toDateString()) {
      return this.currentLang === 'ar' ? '\u0627\u0644\u064a\u0648\u0645' : 'Today';
    }
    if (date.toDateString() === yesterday.toDateString()) {
      return this.currentLang === 'ar' ? '\u0623\u0645\u0633' : 'Yesterday';
    }
    return date.toLocaleDateString(this.currentLang === 'ar' ? 'ar-EG' : 'en-US', {
      month: 'short',
      day: 'numeric',
      year: date.getFullYear() !== today.getFullYear() ? 'numeric' : undefined,
    });
  }

  formatTime(dateString: string): string {
    if (!dateString) {
      return '';
    }
    return new Date(dateString).toLocaleTimeString(this.currentLang === 'ar' ? 'ar-EG' : 'en-US', {
      hour: '2-digit',
      minute: '2-digit',
    });
  }

  onKeyDown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.sendMessage();
    }
  }

  autoGrow(): void {
    if (!this.messageInput) {
      return;
    }
    const ta = this.messageInput.nativeElement;
    ta.style.height = 'auto';
    ta.style.height = Math.min(ta.scrollHeight, 120) + 'px';
  }

  resetTextarea(): void {
    if (this.messageInput) {
      this.messageInput.nativeElement.style.height = 'auto';
    }
  }

  focusInput(): void {
    if (this.messageInput && this.canSend) {
      this.messageInput.nativeElement.focus();
    }
  }

  scrollToBottom(duration = 300): void {
    setTimeout(() => this.chatContent?.scrollToBottom(duration), 50);
  }

  goBack(): void {
    this.nav.back();
  }

  async triggerHaptic(style: 'light' | 'medium' | 'heavy' = 'medium'): Promise<void> {
    try {
      if (this.platform.is('capacitor')) {
        const impact = style === 'light' ? ImpactStyle.Light : style === 'heavy' ? ImpactStyle.Heavy : ImpactStyle.Medium;
        await Haptics.impact({ style: impact });
      }
    } catch {
      /* haptics unavailable */
    }
  }
}
