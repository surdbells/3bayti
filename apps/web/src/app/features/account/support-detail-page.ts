import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor, DatePipe } from '@angular/common';
import { ActivatedRoute, RouterLink } from '@angular/router';
import {
  ReactiveFormsModule,
  FormBuilder,
  Validators,
} from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { ToastService } from '../../shared/forms';
import {
  SupportService,
  TICKET_STATUS_LABELS,
  TICKET_PRIORITY_LABELS,
} from './support.service';
import type {
  TicketDetail,
  TicketMessage,
  TicketStatus,
  TicketPriority,
} from './support.service';

/**
 * /account/support/:id — ticket detail: header (subject/status/
 * priority) + the message thread + a reply box.
 *
 * Layout mirrors the portal's ticket-message component: a scrollable
 * thread of chat bubbles aligned by author (support/admin on the left,
 * the customer on the right via message.is_admin_reply) with a reply
 * composer beneath it. Posting a reply re-opens a resolved/closed
 * ticket server-side; we re-fetch to reflect the new status + message.
 */
@Component({
  selector: 'app-support-detail',
  standalone: true,
  imports: [NgIf, NgFor, DatePipe, RouterLink, ReactiveFormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="support-detail" data-testid="support-detail-page">
      <div class="support-detail__container">
        <nav class="support-detail__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <a routerLink="/account/support">{{ 'account.support.title' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.support.detail.title' | translate }}</span>
        </nav>

        <ng-container *ngIf="ticket() as t; else loadingOrError">
          <header class="support-detail__header">
            <h1 class="support-detail__subject">{{ t.subject }}</h1>
            <div class="support-detail__tags">
              <span class="support-badge" [class]="'support-badge support-badge--' + t.status">
                {{ statusLabel(t.status) | translate }}
              </span>
              <span class="support-detail__priority">
                {{ 'account.support.create.priority' | translate }}:
                {{ priorityLabel(t.priority) | translate }}
              </span>
            </div>
          </header>

          <section class="support-thread" data-testid="support-thread">
            <article
              *ngFor="let m of t.messages; trackBy: trackById"
              class="support-bubble"
              [class.support-bubble--support]="m.is_admin_reply"
              [class.support-bubble--mine]="!m.is_admin_reply"
            >
              <header class="support-bubble__head">
                <span class="support-bubble__author">{{ m.author_name }}</span>
                <span class="support-bubble__time">{{ m.created_at | date: 'short' }}</span>
              </header>
              <p class="support-bubble__body">{{ m.body }}</p>
            </article>
          </section>

          <form
            class="support-reply"
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            novalidate
          >
            <label class="support-reply__label" for="reply-body">
              {{ 'account.support.detail.replyLabel' | translate }}
            </label>
            <textarea
              id="reply-body"
              rows="3"
              formControlName="message"
              class="auth-input support-reply__input"
              [placeholder]="'account.support.detail.replyPlaceholder' | translate"
              data-testid="reply-input"
            ></textarea>
            <div class="support-reply__actions">
              <button
                type="submit"
                class="support-reply__send"
                [disabled]="isSaving() || form.invalid"
                data-testid="reply-send"
              >
                {{ (isSaving() ? 'common.saving' : 'account.support.detail.send') | translate }}
              </button>
            </div>
          </form>
        </ng-container>

        <ng-template #loadingOrError>
          <div *ngIf="!loadError(); else errorState" class="support-detail__loading" data-testid="support-detail-loading">
            {{ 'common.loading' | translate }}
          </div>
          <ng-template #errorState>
            <div class="support-detail__error" data-testid="support-detail-error">
              <p>{{ 'account.support.detail.notFound' | translate }}</p>
              <a routerLink="/account/support" class="support-detail__back">
                {{ 'account.support.detail.backToList' | translate }}
              </a>
            </div>
          </ng-template>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './support.scss',
})
export class SupportDetailPageComponent implements OnInit {
  private readonly support = inject(SupportService);
  private readonly toast = inject(ToastService);
  private readonly route = inject(ActivatedRoute);

  protected readonly isSaving = this.support.isSaving;

  private readonly _ticket = signal<TicketDetail | null>(null);
  protected readonly ticket = this._ticket.asReadonly();

  private readonly _loadError = signal<boolean>(false);
  protected readonly loadError = this._loadError.asReadonly();

  private ticketId = 0;

  protected readonly form = inject(FormBuilder).nonNullable.group({
    message: ['', [Validators.required, Validators.maxLength(5000)]],
  });

  async ngOnInit(): Promise<void> {
    this.ticketId = Number(this.route.snapshot.paramMap.get('id'));
    if (!Number.isInteger(this.ticketId) || this.ticketId <= 0) {
      this._loadError.set(true);
      return;
    }
    await this.load();
  }

  private async load(): Promise<void> {
    try {
      const ticket = await this.support.getTicket(this.ticketId);
      this._ticket.set(ticket);
    } catch {
      this._loadError.set(true);
    }
  }

  protected statusLabel(status: TicketStatus): string {
    return TICKET_STATUS_LABELS[status] ?? status;
  }

  protected priorityLabel(priority: TicketPriority): string {
    return TICKET_PRIORITY_LABELS[priority] ?? priority;
  }

  protected trackById(_index: number, item: TicketMessage): number {
    return item.id;
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }
    const message = this.form.controls.message.value.trim();
    if (message === '') return;

    try {
      await this.support.replyToTicket(this.ticketId, message);
      this.form.reset({ message: '' });
      /* Re-fetch so the new message + any status change (re-opened)
         appear without a manual refresh. */
      await this.load();
    } catch {
      this.toast.error('account.support.detail.errors.replyFailed');
    }
  }
}
