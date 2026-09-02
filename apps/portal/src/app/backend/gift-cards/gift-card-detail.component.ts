import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../services/navigation-history.service';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { AxConfirmService } from '../../shared/overlays';
import { I18nService } from '../../i18n.service';
import { AxComboboxComponent, AxComboboxOption } from '../../shared/forms/ax-combobox.component';
import { AxCopyToClipboardDirective } from '../../shared/rich';
import { apiErrorMessage } from '../../shared/http/api-error';

@Component({
  selector: 'app-gift-card-detail',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, TranslatePipe, AxComboboxComponent, AxCopyToClipboardDirective],
  styles: [`
    /* Consistent inner padding for the summary / delivery / ledger cards. */
    .gc-summary,
    .gc-delivery {
      padding: 1.5rem;
    }
    /* Responsive 2-col grid with even gutters; collapses to 1 col when narrow. */
    .gc-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(15rem, 1fr));
      gap: 1.25rem 2rem;
    }
    /* Each label/value pair: label on top, readable spacing to the value. */
    .gc-grid > .gc-cell {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      min-width: 0;
    }
    .gc-grid > .gc-cell .ax-field-label {
      font-size: 0.75rem;
      font-weight: 600;
      color: var(--ax-text-secondary, #64748b);
      text-transform: uppercase;
      letter-spacing: 0.02em;
    }
    .gc-grid > .gc-cell > div {
      word-break: break-word;
    }
    .gc-delivery-title {
      margin: 0 0 1.25rem;
    }
    /* Ledger header lines up with the table edge padding. */
    .gc-ledger-header {
      padding: 1.25rem 1.5rem;
    }
    /* Card-number title with an inline copy affordance. */
    .gc-code-title {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
    }
    .gc-code-copy {
      padding: 0.25rem;
    }
    /* Header actions wrap gracefully on narrow screens. */
    .gc-actions {
      flex-wrap: wrap;
      justify-content: flex-end;
    }
    /* Recipient phone with inline copy + WhatsApp affordances. */
    .gc-phone-row {
      display: flex;
      align-items: center;
      gap: 0.25rem;
      flex-wrap: wrap;
    }
    .gc-inline-btn {
      padding: 0.15rem 0.35rem;
    }
  `],
  template: `
<app-admin-shell>
  <div class="ax-container">
    <header class="ax-page-header">
      <div class="ax-page-header-content">
        <button type="button" (click)="goBack()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
          <app-icon name="arrow_back" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.back_to_list' | translate }}
        </button>
        <span class="ax-page-header-eyebrow">{{ 'gift_cards_admin.eyebrow' | translate }}</span>
        <h1 class="ax-page-title gc-code-title" *ngIf="card">
          <span>{{ card.code }}</span>
          <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm gc-code-copy"
            [axCopyToClipboard]="card.code" [axCopyLabel]="'gift_cards_admin.copy_card_number' | translate"
            [attr.aria-label]="'gift_cards_admin.copy_card_number' | translate">
            <app-icon name="content_copy" aria-hidden="true"></app-icon>
          </button>
        </h1>
      </div>
      <div class="ax-flex ax-gap-2 ax-items-center gc-actions" *ngIf="card">
        <button type="button" class="ax-btn ax-btn-primary ax-btn-sm"
          (click)="startSend()"
          [disabled]="sending || !card.delivery?.can_send"
          [title]="!card.delivery?.can_send ? ('gift_cards_admin.send_unavailable' | translate) : ''">
          <span *ngIf="sending" class="ax-spinner ax-spinner-sm" aria-hidden="true"></span>
          <app-icon *ngIf="!sending" name="send" aria-hidden="true"></app-icon>
          {{ 'gift_cards_admin.send_now' | translate }}
        </button>
        <button type="button" class="ax-btn ax-btn-outline ax-btn-sm"
          [axCopyToClipboard]="copyBlock" [axCopyLabel]="'gift_cards_admin.copy_details_done' | translate">
          <app-icon name="content_copy" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.copy_details' | translate }}
        </button>
        <button type="button" class="ax-btn ax-btn-outline ax-btn-sm" (click)="openAdjust()" [disabled]="card.status === 'voided'">
          <app-icon name="tune" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.adjust_balance' | translate }}
        </button>
        <button type="button" class="ax-btn ax-btn-danger ax-btn-sm" (click)="startVoid()" [disabled]="card.status === 'voided'">
          <app-icon name="block" aria-hidden="true"></app-icon> {{ 'gift_cards_admin.void' | translate }}
        </button>
      </div>
    </header>

    <div *ngIf="loading" class="ax-flex ax-justify-center ax-py-8">
      <span class="ax-spinner ax-spinner-lg"></span>
    </div>

    <ng-container *ngIf="!loading && card">
      <!-- Summary -->
      <section class="ax-card ax-p-0 ax-mb-4 gc-summary">
        <div class="gc-grid">
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_status' | translate }}</span>
            <div><span class="ax-badge" [ngClass]="statusBadgeClass(card.status)">{{ statusLabel(card.status) }}</span></div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_balance' | translate }}</span>
            <div class="ax-h4 ax-m-0">{{ card.balance }} {{ card.currency }}</div>
            <span class="ax-text-sm ax-text-secondary">{{ 'gift_cards_admin.detail_denomination' | translate }}: {{ card.denomination }} {{ card.currency }}</span>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_theme' | translate }}</span>
            <div>{{ card.theme || '—' }}</div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_spendable' | translate }}</span>
            <div>{{ (card.is_spendable ? 'gift_cards_admin.delivered_yes' : 'gift_cards_admin.delivered_no') | translate }}</div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_purchaser' | translate }}</span>
            <div>{{ card.purchaser?.name || '—' }}</div>
            <span class="ax-text-sm ax-text-secondary">{{ card.purchaser?.email }}</span>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_recipient' | translate }}</span>
            <div>{{ card.recipient_name || card.recipient_user?.name || '—' }}</div>
            <span class="ax-text-sm ax-text-secondary">{{ card.recipient_email || card.recipient_user?.email }}</span>
            <span *ngIf="card.recipient_phone" class="ax-block ax-text-sm ax-text-secondary">{{ card.recipient_phone }}</span>
          </div>
          <div class="gc-cell" *ngIf="card.recipient_message">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_message' | translate }}</span>
            <div class="ax-text-sm">{{ card.recipient_message }}</div>
          </div>
          <div class="gc-cell" *ngIf="card.purchase_order_reference">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_order_ref' | translate }}</span>
            <div>{{ card.purchase_order_reference }}</div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_created' | translate }}</span>
            <div>{{ fmtDate(card.created_at) }}</div>
          </div>
          <div class="gc-cell" *ngIf="card.expires_at">
            <span class="ax-field-label">{{ 'gift_cards_admin.detail_expires' | translate }}</span>
            <div>{{ fmtDate(card.expires_at) }}</div>
          </div>
        </div>
      </section>

      <!-- Delivery -->
      <section class="ax-card ax-p-0 ax-mb-4 gc-delivery" *ngIf="card.delivery">
        <h3 class="ax-h5 gc-delivery-title">{{ 'gift_cards_admin.delivery' | translate }}</h3>
        <div class="gc-grid">
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_channel' | translate }}</span>
            <div>{{ card.delivery.channel || '—' }}</div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_delivered' | translate }}</span>
            <div>
              <span class="ax-badge" [class.ax-badge-success]="card.delivery.delivered" [class.ax-badge-neutral]="!card.delivery.delivered">
                {{ (card.delivery.delivered ? 'gift_cards_admin.delivered_yes' : 'gift_cards_admin.delivered_no') | translate }}
              </span>
            </div>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_email' | translate }}</span>
            <div>{{ card.delivery.recipient_email || '—' }}</div>
            <span *ngIf="card.delivery.email_delivered_at" class="ax-text-sm ax-text-secondary">{{ fmtDate(card.delivery.email_delivered_at) }}</span>
          </div>
          <div class="gc-cell">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_phone' | translate }}</span>
            <div class="gc-phone-row">
              <span>{{ card.delivery.recipient_phone || '—' }}</span>
              <ng-container *ngIf="card.delivery.recipient_phone">
                <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm gc-inline-btn"
                  [axCopyToClipboard]="card.delivery.recipient_phone"
                  [axCopyLabel]="'gift_cards_admin.copy_phone_done' | translate"
                  [attr.aria-label]="'gift_cards_admin.copy_phone' | translate">
                  <app-icon name="content_copy" aria-hidden="true"></app-icon>
                </button>
                <a *ngIf="waLink" [href]="waLink" target="_blank" rel="noopener"
                  class="ax-btn ax-btn-ghost ax-btn-sm gc-inline-btn"
                  [attr.aria-label]="'gift_cards_admin.open_whatsapp' | translate"
                  [title]="'gift_cards_admin.open_whatsapp' | translate">
                  <app-icon name="chat" aria-hidden="true"></app-icon>
                </a>
              </ng-container>
            </div>
            <span *ngIf="card.delivery.sms_delivered_at" class="ax-text-sm ax-text-secondary">
              {{ 'gift_cards_admin.delivery_sent_at' | translate }}: {{ fmtDate(card.delivery.sms_delivered_at) }}
            </span>
          </div>
          <div class="gc-cell" *ngIf="card.delivery.scheduled_at">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_scheduled' | translate }}</span>
            <div>{{ fmtDate(card.delivery.scheduled_at) }}</div>
            <span class="ax-badge" [class.ax-badge-warning]="scheduleInfo?.pending" [class.ax-badge-neutral]="!scheduleInfo?.pending" *ngIf="!card.delivery.delivered">
              {{ (scheduleInfo?.pending ? 'gift_cards_admin.schedule_pending' : 'gift_cards_admin.schedule_due') | translate }}
            </span>
          </div>
        </div>
      </section>

      <!-- Ledger -->
      <section class="ax-card ax-p-0">
        <header class="gc-ledger-header">
          <h3 class="ax-h5 ax-m-0">{{ 'gift_cards_admin.ledger' | translate }}</h3>
        </header>
        <div class="ax-table-wrapper">
          <table class="ax-table ax-table-hover">
            <thead>
              <tr>
                <th>{{ 'gift_cards_admin.ledger_type' | translate }}</th>
                <th>{{ 'gift_cards_admin.ledger_amount' | translate }}</th>
                <th>{{ 'gift_cards_admin.ledger_balance_after' | translate }}</th>
                <th>{{ 'gift_cards_admin.ledger_reason' | translate }}</th>
                <th>{{ 'gift_cards_admin.ledger_order_ref' | translate }}</th>
                <th>{{ 'gift_cards_admin.ledger_date' | translate }}</th>
              </tr>
            </thead>
            <tbody>
              <tr *ngFor="let row of card.ledger">
                <td><span class="ax-badge" [ngClass]="ledgerBadgeClass(row.type)">{{ row.type }}</span></td>
                <td><span [class.ax-text-success]="row.type === 'credit'" [class.ax-text-danger]="row.type === 'debit'">{{ row.amount }} {{ card.currency }}</span></td>
                <td>{{ row.balance_after }} {{ card.currency }}</td>
                <td>{{ row.reason || '—' }}</td>
                <td>{{ row.order_reference || '—' }}</td>
                <td>{{ fmtDate(row.created_at) }}</td>
              </tr>
              <tr *ngIf="!card.ledger?.length">
                <td colspan="6" class="ax-text-center ax-text-secondary ax-py-4">{{ 'gift_cards_admin.ledger_empty' | translate }}</td>
              </tr>
            </tbody>
          </table>
        </div>
      </section>
    </ng-container>

    <section *ngIf="!loading && !card" class="ax-card ax-p-8 ax-text-center">
      <app-icon name="card_giftcard" style="font-size:3rem;color:var(--ax-color-text-tertiary)"></app-icon>
      <h3 class="ax-h4 ax-m-0 ax-mt-3">{{ 'gift_cards_admin.not_found' | translate }}</h3>
    </section>
  </div>

  <!-- Adjust dialog -->
  <div *ngIf="adjusting" class="ax-backdrop" style="position:fixed;inset:0;z-index:1000;display:flex;align-items:center;justify-content:center;padding:1.5rem;background:rgba(0,0,0,.45)" (click)="closeAdjust()">
    <div class="ax-modal ax-modal-sm" (click)="$event.stopPropagation()" role="dialog" aria-modal="true">
      <header class="ax-modal-header">
        <h2 class="ax-modal-title">{{ 'gift_cards_admin.adjust_balance' | translate }}</h2>
        <button type="button" class="ax-modal-close" (click)="closeAdjust()"><app-icon name="close"></app-icon></button>
      </header>
      <div class="ax-modal-body">
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.adjust_type' | translate }}</span>
          <app-ax-combobox [(ngModel)]="adjustForm.type" [ngModelOptions]="{ standalone: true }"
            [options]="adjustTypeOptions" [searchable]="false" [allowClear]="false"
            [ariaLabel]="'gift_cards_admin.adjust_type' | translate"></app-ax-combobox>
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.adjust_amount' | translate }}</span>
          <input type="number" min="0.01" step="0.01" class="ax-input" [(ngModel)]="adjustForm.amount" placeholder="25.00" />
        </label>
        <label class="ax-field">
          <span class="ax-field-label">{{ 'gift_cards_admin.adjust_reason' | translate }}</span>
          <textarea class="ax-input" rows="2" [(ngModel)]="adjustForm.reason"></textarea>
        </label>
        <p *ngIf="adjustError" class="ax-text-danger ax-text-sm ax-m-0">{{ adjustError }}</p>
      </div>
      <footer class="ax-modal-footer">
        <button type="button" class="ax-btn ax-btn-ghost" (click)="closeAdjust()">{{ 'gift_cards_admin.cancel' | translate }}</button>
        <button type="button" class="ax-btn ax-btn-primary" [disabled]="adjustSaving || !adjustForm.amount" (click)="submitAdjust()">
          {{ 'gift_cards_admin.adjust_submit' | translate }}
        </button>
      </footer>
    </div>
  </div>
</app-admin-shell>
  `,
})
export class GiftCardDetailComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly navHistory = inject(NavigationHistoryService);
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly confirm = inject(AxConfirmService);
  private readonly i18n = inject(I18nService);
  get adjustTypeOptions(): AxComboboxOption[] {
    return [
      { id: 'credit', label: this.i18n.t('gift_cards_admin.adjust_credit') },
      { id: 'debit', label: this.i18n.t('gift_cards_admin.adjust_debit') },
    ];
  }

  id = '';
  card: any = null;
  loading = false;
  sending = false;

  adjusting = false;
  adjustSaving = false;
  adjustError = '';
  adjustForm = { type: 'credit' as 'credit' | 'debit', amount: '', reason: '' };

  ngOnInit() {
    this.id = this.route.snapshot.paramMap.get('id') ?? '';
    this.load();
  }

  load() {
    if (!this.id) return;
    this.loading = true;
    this.adapter.get_v3('GET /admin/gift-cards/:id', { params: { id: this.id } }).subscribe({
      next: (res: any) => {
        this.card = res?.data ?? res ?? null;
        this.loading = false;
      },
      error: (err: any) => {
        this.card = null;
        this.loading = false;
        this.toast.error(apiErrorMessage(err, 'Unable to load gift card.'));
      },
    });
  }

  statusLabel(status: string): string {
    return String(status ?? '').replace(/_/g, ' ');
  }

  statusBadgeClass(status: string): string {
    switch (status) {
      case 'active': return 'ax-badge-success';
      case 'partially_used': return 'ax-badge-info';
      case 'pending_payment': return 'ax-badge-warning';
      case 'voided': return 'ax-badge-danger';
      default: return 'ax-badge-neutral';
    }
  }

  ledgerBadgeClass(type: string): string {
    switch (type) {
      case 'credit': return 'ax-badge-success';
      case 'debit': return 'ax-badge-warning';
      case 'void': return 'ax-badge-danger';
      default: return 'ax-badge-neutral';
    }
  }

  fmtDate(v: any): string {
    return v ? new Date(String(v)).toLocaleString() : '—';
  }

  // ── Copy / WhatsApp outreach ────────────────────────────────────────
  /**
   * A WhatsApp-ready block for reaching out to the recipient manually:
   * card number, amount, who it's from, the personal message, and the
   * recipient's phone. Copied via the axCopyToClipboard directive.
   */
  get copyBlock(): string {
    const c = this.card;
    if (!c) return '';
    const cur = c.currency || 'AED';
    const lines: string[] = [
      this.i18n.t('gift_cards_admin.copy_heading'),
      `${this.i18n.t('gift_cards_admin.copy_card_number')}: ${c.code}`,
    ];
    if (c.denomination) lines.push(`${this.i18n.t('gift_cards_admin.detail_denomination')}: ${c.denomination} ${cur}`);
    if (c.purchaser?.name) lines.push(`${this.i18n.t('gift_cards_admin.copy_from')}: ${c.purchaser.name}`);
    if (c.recipient_message) lines.push(`${this.i18n.t('gift_cards_admin.detail_message')}: ${c.recipient_message}`);
    const phone = c.recipient_phone || c.delivery?.recipient_phone;
    if (phone) lines.push(`${this.i18n.t('gift_cards_admin.delivery_phone')}: ${phone}`);
    return lines.join('\n');
  }

  /** wa.me deep link to the recipient (digits only), or '' if no phone. */
  get waLink(): string {
    const phone = this.card?.recipient_phone || this.card?.delivery?.recipient_phone;
    if (!phone) return '';
    const digits = String(phone).replace(/[^0-9]/g, '');
    return digits ? `https://wa.me/${digits}` : '';
  }

  /** Scheduling state for the delivery block: pending = future + not sent. */
  get scheduleInfo(): { pending: boolean } | null {
    const at = this.card?.delivery?.scheduled_at;
    if (!at) return null;
    const when = new Date(String(at)).getTime();
    const pending = !this.card?.delivery?.delivered && Number.isFinite(when) && when > Date.now();
    return { pending };
  }

  // ── Adjust ──────────────────────────────────────────────────────────
  openAdjust() {
    this.adjustForm = { type: 'credit', amount: '', reason: '' };
    this.adjustError = '';
    this.adjusting = true;
  }
  closeAdjust() { this.adjusting = false; }

  submitAdjust() {
    const amount = Number(this.adjustForm.amount);
    if (!amount || amount <= 0) {
      this.adjustError = 'Enter a positive amount.';
      return;
    }
    const body: any = {
      type: this.adjustForm.type,
      amount: amount.toFixed(2),
      reason: this.adjustForm.reason?.trim() || undefined,
    };
    this.adjustSaving = true;
    this.adjustError = '';
    this.adapter.post_v3('POST /admin/gift-cards/:id/adjust', body, { params: { id: this.id } }).subscribe({
      next: () => {
        this.adjustSaving = false;
        this.adjusting = false;
        this.toast.success('Balance adjusted.');
        this.load();
      },
      error: (err: any) => {
        this.adjustSaving = false;
        const code = err?.error?.error?.code;
        const apiMsg = err?.error?.error?.message;
        if (err?.status === 422 || code === 'gift_card_overdraw') {
          const bal = err?.error?.error?.details?.balance;
          this.adjustError = bal != null
            ? `Debit exceeds the available balance (${bal} ${this.card?.currency ?? ''}).`
            : (apiMsg ?? 'Debit exceeds the available balance.');
        } else if (err?.status === 409 || code === 'gift_card_voided') {
          this.adjustError = apiMsg ?? 'This gift card has been voided and cannot be adjusted.';
        } else {
          this.adjustError = apiMsg ?? 'Failed to adjust balance.';
        }
      },
    });
  }

  // ── Void ────────────────────────────────────────────────────────────
  startVoid() {
    this.confirm.confirm({
      title: 'Void gift card',
      message: 'Voiding zeroes the balance and disables the card. This cannot be undone.',
      confirmLabel: 'Void',
      cancelLabel: 'Cancel',
      variant: 'danger',
    }).then((ok) => {
      if (!ok) return;
      const reason = 'Voided by admin';
      this.adapter.post_v3('POST /admin/gift-cards/:id/void', { reason }, { params: { id: this.id } }).subscribe({
        next: (res: any) => {
          const data = res?.data ?? res;
          this.toast.success(data?.already_voided ? 'Gift card was already voided.' : 'Gift card voided.');
          this.load();
        },
        error: (err: any) => {
          this.toast.error(err?.error?.error?.message ?? 'Failed to void gift card.');
        },
      });
    });
  }

  // ── Send / resend to recipient ──────────────────────────────────────
  startSend() {
    if (!this.card || !this.card.delivery?.can_send || this.sending) return;

    const targets: string[] = [];
    if (this.card.delivery?.recipient_email) targets.push(this.card.delivery.recipient_email);
    if (this.card.delivery?.recipient_phone) targets.push(this.card.delivery.recipient_phone);
    const to = targets.join(', ');

    this.confirm.confirm({
      title: this.i18n.t('gift_cards_admin.send_confirm_title'),
      message: this.i18n.t('gift_cards_admin.send_confirm_message') + (to ? '\n\n' + to : ''),
      confirmLabel: this.i18n.t('gift_cards_admin.send_now'),
      cancelLabel: this.i18n.t('gift_cards_admin.cancel'),
      variant: 'default',
    }).then((ok) => {
      if (!ok) return;
      this.sending = true;
      this.adapter.post_v3('POST /admin/gift-cards/:id/send', {}, { params: { id: this.id } }).subscribe({
        next: (res: any) => {
          this.sending = false;
          const result = (res?.data ?? res)?.result ?? {};
          const anySent = result.email === 'sent' || result.sms === 'sent';
          const msg = this.sendResultMessage(result);
          if (anySent) this.toast.success(msg); else this.toast.error(msg);
          this.load();
        },
        error: (err: any) => {
          this.sending = false;
          this.toast.error(apiErrorMessage(err, this.i18n.t('gift_cards_admin.send_failed')));
        },
      });
    });
  }

  /** Human summary of the per-channel send result for the toast. */
  private sendResultMessage(result: { email?: string; sms?: string }): string {
    const parts: string[] = [];
    if (result.email === 'sent') parts.push(this.i18n.t('gift_cards_admin.send_email_sent'));
    else if (result.email === 'failed') parts.push(this.i18n.t('gift_cards_admin.send_email_failed'));
    if (result.sms === 'sent') parts.push(this.i18n.t('gift_cards_admin.send_sms_sent'));
    else if (result.sms === 'failed') parts.push(this.i18n.t('gift_cards_admin.send_sms_failed'));
    else if (result.sms === 'not_configured') parts.push(this.i18n.t('gift_cards_admin.send_sms_not_configured'));
    return parts.length ? parts.join(' · ') : this.i18n.t('gift_cards_admin.send_nothing');
  }

  goBack() { this.navHistory.back('/admin-gift-cards'); }
}
