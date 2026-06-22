import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
  OnDestroy,
} from '@angular/core';
import { NgIf, NgFor, DatePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import { HttpErrorResponse } from '@angular/common/http';
import {
  ReactiveFormsModule,
  FormBuilder,
  Validators,
} from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { ToastService } from '../../shared/forms';
import { LocaleService } from '../../core/i18n/locale.service';
import { MessagesService } from './messages.service';
import type { ConversationSummary, ChatMessage } from './messages.service';

/** Polling cadence for new messages — mirrors mobile's 5s loop. */
const POLL_INTERVAL_MS = 5000;

/**
 * /account/messages/:uuid — one order conversation.
 *
 * Auth-gated. Renders the message thread as sent/received bubbles
 * (customer's own messages on the trailing side, the vendor's on the
 * leading side), a composer to send a text reply, and a light 5-second
 * poll that appends only newer messages via the `after_id` cursor. The
 * conversation is marked read on open (and after each poll that brings
 * in vendor messages) so the inbox badge clears.
 *
 * The v3 moderator can reject a send with 422 CHAT_MESSAGE_BLOCKED when
 * the content looks like contact details; we surface that server message
 * as a warning toast and keep the composer text so the user can edit.
 */
@Component({
  selector: 'app-messages-thread',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, ReactiveFormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="messages-thread" data-testid="messages-thread-page">
      <div class="messages-thread__container">
        <nav class="messages-thread__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <a routerLink="/account/messages">{{ 'account.messages.title' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ headerName() }}</span>
        </nav>

        <ng-container *ngIf="!loadError(); else errorState">
          <header class="messages-thread__header" *ngIf="conversation() as c">
            <div class="messages-thread__heading">
              <h1 class="messages-thread__vendor">{{ c.counterparty.name }}</h1>
              <p class="messages-thread__order">
                {{ 'account.messages.list.order' | translate: { ref: c.order_reference } }}
                <ng-container *ngIf="c.item.name"> · {{ c.item.name }}</ng-container>
              </p>
            </div>
          </header>

          <section class="message-thread" data-testid="message-thread">
            <ng-container *ngIf="messages().length > 0; else threadEmpty">
              <article
                *ngFor="let m of messages(); trackBy: trackById"
                class="message-bubble"
                [class.message-bubble--mine]="isMine(m)"
                [class.message-bubble--theirs]="!isMine(m)"
                [class.message-bubble--system]="m.sender_type === 'system'"
              >
                <p class="message-bubble__body">{{ bodyOf(m) }}</p>
                <span class="message-bubble__time">{{ m.created_at | date: 'short' }}</span>
              </article>
            </ng-container>
            <ng-template #threadEmpty>
              <p class="message-thread__empty" *ngIf="!isLoading()">
                {{ 'account.messages.thread.empty' | translate }}
              </p>
            </ng-template>
          </section>

          <form
            class="message-composer"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
          >
            <label class="message-composer__label" for="composer-input">
              {{ 'account.messages.thread.replyLabel' | translate }}
            </label>
            <div class="message-composer__row">
              <textarea
                id="composer-input"
                rows="2"
                formControlName="content"
                class="auth-input message-composer__input"
                [placeholder]="'account.messages.thread.placeholder' | translate"
                data-testid="composer-input"
              ></textarea>
              <button
                type="submit"
                class="message-composer__send"
                [disabled]="isSending() || form.invalid"
                data-testid="composer-send"
              >
                {{ (isSending() ? 'common.sending' : 'account.messages.thread.send') | translate }}
              </button>
            </div>
          </form>
        </ng-container>

        <ng-template #errorState>
          <div class="messages-thread__error" data-testid="messages-thread-error">
            <p>{{ 'account.messages.thread.notFound' | translate }}</p>
            <a routerLink="/account/messages" class="messages-thread__back">
              {{ 'account.messages.thread.backToList' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './messages.scss',
})
export class MessagesThreadPageComponent implements OnInit, OnDestroy {
  private readonly chat = inject(MessagesService);
  private readonly toast = inject(ToastService);
  private readonly route = inject(ActivatedRoute);
  private readonly locale = inject(LocaleService);

  protected readonly isLoading = this.chat.isLoading;
  protected readonly isSending = this.chat.isSending;

  private readonly _conversation = signal<ConversationSummary | null>(null);
  protected readonly conversation = this._conversation.asReadonly();

  private readonly _messages = signal<ChatMessage[]>([]);

  private readonly _loadError = signal<boolean>(false);
  protected readonly loadError = this._loadError.asReadonly();

  protected readonly headerName = computed(() => {
    const c = this._conversation();
    return c?.counterparty.name ?? '';
  });

  private uuid = '';
  private highestId = 0;
  private pollTimer: ReturnType<typeof setInterval> | null = null;

  protected readonly form = inject(FormBuilder).nonNullable.group({
    content: ['', [Validators.required, Validators.maxLength(4000)]],
  });

  /** Template helper — the rendered message list. */
  protected messages(): ChatMessage[] {
    return this._messages();
  }

  async ngOnInit(): Promise<void> {
    this.uuid = this.route.snapshot.paramMap.get('uuid') ?? '';
    if (this.uuid.trim() === '') {
      this._loadError.set(true);
      return;
    }
    await this.loadInitial();
    /* Light poll for vendor replies, like the mobile chat screen. */
    this.pollTimer = setInterval(() => void this.poll(), POLL_INTERVAL_MS);
  }

  ngOnDestroy(): void {
    if (this.pollTimer !== null) {
      clearInterval(this.pollTimer);
      this.pollTimer = null;
    }
  }

  private async loadInitial(): Promise<void> {
    try {
      const result = await this.chat.getMessages(this.uuid);
      this._conversation.set(result.conversation);
      this._messages.set(result.messages);
      this.highestId = this.maxId(result.messages);
      /* Mark read on open so the inbox badge clears. */
      await this.chat.markAsRead(this.uuid);
    } catch {
      this._loadError.set(true);
    }
  }

  private async poll(): Promise<void> {
    if (this._loadError() || this.isSending()) return;
    try {
      const result = await this.chat.getMessages(this.uuid, this.highestId);
      if (result.messages.length > 0) {
        this.appendNew(result.messages);
        /* New (possibly vendor) messages arrived while viewing — clear them. */
        await this.chat.markAsRead(this.uuid);
      }
    } catch {
      /* Transient poll failure is non-fatal; the next tick retries. */
    }
  }

  private appendNew(incoming: ChatMessage[]): void {
    const seen = new Set(this._messages().map((m) => m.id));
    const fresh = incoming.filter((m) => m.id > 0 && !seen.has(m.id));
    if (fresh.length === 0) return;
    this._messages.update((cur) => [...cur, ...fresh]);
    this.highestId = Math.max(this.highestId, this.maxId(fresh));
  }

  private maxId(list: ChatMessage[]): number {
    return list.reduce((max, m) => (m.id > max ? m.id : max), this.highestId);
  }

  protected isMine(m: ChatMessage): boolean {
    return m.sender_type === 'customer';
  }

  protected bodyOf(m: ChatMessage): string {
    if (this.locale.current() === 'ar' && m.content_ar) {
      return m.content_ar;
    }
    return m.content ?? '';
  }

  protected trackById(_index: number, item: ChatMessage): number {
    return item.id;
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSending()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const content = this.form.controls.content.value.trim();
    if (content === '') return;

    try {
      const sent = await this.chat.sendMessage(this.uuid, content);
      this.appendNew([sent]);
      this.form.reset({ content: '' });
    } catch (err) {
      const message = this.blockedMessage(err);
      if (message !== null) {
        /* 422 CHAT_MESSAGE_BLOCKED — keep the text so the user can edit. */
        this.toast.warning(message);
      } else {
        this.toast.error('account.messages.thread.errors.sendFailed');
      }
    }
  }

  /**
   * Extract the human-readable moderation message from a 422
   * CHAT_MESSAGE_BLOCKED error, or null for any other error.
   */
  private blockedMessage(err: unknown): string | null {
    if (err instanceof HttpErrorResponse && err.status === 422) {
      const body = err.error as { code?: string; message?: string } | null;
      if (body?.code === 'CHAT_MESSAGE_BLOCKED' && typeof body.message === 'string') {
        return body.message;
      }
    }
    return null;
  }
}
