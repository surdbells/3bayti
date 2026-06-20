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
import {
  SupportService,
  TICKET_STATUS_LABELS,
} from './support.service';
import type { TicketListItem, TicketStatus } from './support.service';

/**
 * /account/support — the customer's support tickets.
 *
 * Auth-gated. Lists the user's tickets (subject, status badge, last
 * update, message count) with an empty state and a "New ticket" CTA.
 * Each row links to /account/support/:id for the thread + reply.
 *
 * Status badges use the lowercase API vocabulary (open / pending /
 * resolved / closed); colour is keyed by status via a CSS modifier.
 */
@Component({
  selector: 'app-support-tickets',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="support-list" data-testid="support-tickets-page">
      <div class="support-list__container">
        <nav class="support-list__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.support.title' | translate }}</span>
        </nav>

        <header class="support-list__header">
          <div>
            <h1 class="support-list__title">{{ 'account.support.title' | translate }}</h1>
            <p class="support-list__intro">{{ 'account.support.intro' | translate }}</p>
          </div>
          <a
            routerLink="/account/support/new"
            class="support-list__new"
            data-testid="support-new-cta"
          >
            {{ 'account.support.list.new' | translate }}
          </a>
        </header>

        <ng-container *ngIf="!isLoading() || tickets().length > 0; else loadingState">
          <ng-container *ngIf="tickets().length > 0; else emptyState">
            <ul class="support-list__items" data-testid="support-list">
              <li *ngFor="let t of tickets(); trackBy: trackById">
                <a
                  class="support-card"
                  [routerLink]="['/account/support', t.id]"
                  [attr.data-testid]="'support-row-' + t.id"
                >
                  <div class="support-card__main">
                    <span class="support-card__subject">{{ t.subject }}</span>
                    <span class="support-card__summary">{{ t.summary }}</span>
                  </div>
                  <div class="support-card__meta">
                    <span
                      class="support-badge"
                      [class]="'support-badge support-badge--' + t.status"
                    >
                      {{ statusLabel(t.status) | translate }}
                    </span>
                    <span class="support-card__updated">
                      {{ 'account.support.list.updated' | translate }}
                      {{ t.updated_at | date: 'mediumDate' }}
                    </span>
                    <span class="support-card__count">
                      {{ 'account.support.list.messages' | translate: { count: t.message_count } }}
                    </span>
                  </div>
                </a>
              </li>
            </ul>
          </ng-container>
        </ng-container>

        <ng-template #emptyState>
          <div class="support-list__empty" data-testid="support-empty">
            <p class="support-list__empty-title">{{ 'account.support.list.empty' | translate }}</p>
            <p class="support-list__empty-body">{{ 'account.support.list.emptyBody' | translate }}</p>
            <a routerLink="/account/support/new" class="support-list__new">
              {{ 'account.support.list.new' | translate }}
            </a>
          </div>
        </ng-template>

        <ng-template #loadingState>
          <div class="support-list__loading" data-testid="support-loading">
            {{ 'common.loading' | translate }}
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './support.scss',
})
export class SupportTicketsPageComponent implements OnInit {
  private readonly support = inject(SupportService);
  private readonly toast = inject(ToastService);

  protected readonly isLoading = this.support.isLoading;
  private readonly _tickets = signal<TicketListItem[]>([]);
  protected readonly tickets = this._tickets.asReadonly();

  async ngOnInit(): Promise<void> {
    try {
      const result = await this.support.listTickets({ limit: 50 });
      this._tickets.set(result.tickets);
    } catch {
      this.toast.error('account.support.errors.loadFailed');
    }
  }

  protected statusLabel(status: TicketStatus): string {
    return TICKET_STATUS_LABELS[status] ?? status;
  }

  protected trackById(_index: number, item: TicketListItem): number {
    return item.id;
  }
}
