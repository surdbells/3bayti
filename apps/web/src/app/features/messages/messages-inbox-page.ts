import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor, DatePipe } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { ToastService } from '../../shared/forms';
import { MessagesService } from './messages.service';
import type { ConversationSummary } from './messages.service';

/**
 * /account/messages — the customer's order chat inbox.
 *
 * Auth-gated. Lists every order conversation the server has provisioned
 * (one per paid order item): the vendor name, the order reference + item
 * snapshot, a last-message preview, an unread-count pill, and the last
 * activity time. Each row links to /account/messages/:uuid for the
 * thread. There is no "start a new chat" action — conversations exist
 * only for orders, created server-side on payment.
 */
@Component({
  selector: 'app-messages-inbox',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="messages-list" data-testid="messages-inbox-page">
      <div class="messages-list__container">
        <nav class="messages-list__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.messages.title' | translate }}</span>
        </nav>

        <header class="messages-list__header">
          <h1 class="messages-list__title">{{ 'account.messages.title' | translate }}</h1>
          <p class="messages-list__intro">{{ 'account.messages.intro' | translate }}</p>
        </header>

        <ng-container *ngIf="!isLoading() || conversations().length > 0; else loadingState">
          <ng-container *ngIf="conversations().length > 0; else emptyState">
            <ul class="messages-list__items" data-testid="messages-list">
              <li *ngFor="let c of conversations(); trackBy: trackByUuid">
                <a
                  class="conversation-card"
                  [class.conversation-card--unread]="c.unread_count > 0"
                  [routerLink]="['/account/messages', c.uuid]"
                  [attr.data-testid]="'conversation-row-' + c.uuid"
                >
                  <span class="conversation-card__avatar" aria-hidden="true">
                    <img
                      *ngIf="c.item.image; else letterAvatar"
                      class="conversation-card__avatar-img"
                      [src]="c.item.image"
                      alt=""
                    />
                    <ng-template #letterAvatar>{{ initial(c) }}</ng-template>
                  </span>

                  <div class="conversation-card__main">
                    <div class="conversation-card__top">
                      <span class="conversation-card__vendor">{{ c.counterparty.name }}</span>
                      <span class="conversation-card__time" *ngIf="c.last_message_at">
                        {{ c.last_message_at | date: 'short' }}
                      </span>
                    </div>
                    <span class="conversation-card__order">
                      {{ 'account.messages.list.order' | translate: { ref: c.order_reference } }}
                      <ng-container *ngIf="c.item.name"> · {{ c.item.name }}</ng-container>
                    </span>
                    <span class="conversation-card__preview">
                      {{ c.last_message_preview || ('account.messages.list.noMessages' | translate) }}
                    </span>
                  </div>

                  <span
                    *ngIf="c.unread_count > 0"
                    class="conversation-card__badge"
                    [attr.aria-label]="'account.messages.list.unreadAria' | translate: { count: c.unread_count }"
                  >
                    {{ c.unread_count }}
                  </span>
                </a>
              </li>
            </ul>
          </ng-container>
        </ng-container>

        <ng-template #emptyState>
          <div class="messages-list__empty" data-testid="messages-empty">
            <p class="messages-list__empty-title">{{ 'account.messages.list.empty' | translate }}</p>
            <p class="messages-list__empty-body">{{ 'account.messages.list.emptyBody' | translate }}</p>
          </div>
        </ng-template>

        <ng-template #loadingState>
          <div class="messages-list__loading" data-testid="messages-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './messages.scss',
})
export class MessagesInboxPageComponent implements OnInit {
  private readonly messages = inject(MessagesService);
  private readonly toast = inject(ToastService);

  protected readonly isLoading = this.messages.isLoading;
  private readonly _conversations = signal<ConversationSummary[]>([]);
  protected readonly conversations = this._conversations.asReadonly();

  async ngOnInit(): Promise<void> {
    try {
      const result = await this.messages.listConversations({ limit: 50 });
      this._conversations.set(result.conversations);
    } catch {
      this.toast.error('account.messages.errors.loadFailed');
    }
  }

  protected initial(c: ConversationSummary): string {
    const name = c.counterparty.name?.trim() ?? '';
    return name !== '' ? name[0].toUpperCase() : '?';
  }

  protected trackByUuid(_index: number, item: ConversationSummary): string {
    return item.uuid;
  }
}
