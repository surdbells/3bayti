import { Component, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { TranslatePipe } from '../../translate.pipe';
import { AxConfirmService } from '../../shared/overlays';

@Component({
  selector: 'app-gift-card-detail',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent, TranslatePipe],
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
        <h1 class="ax-page-title" *ngIf="card">{{ card.code }}</h1>
      </div>
      <div class="ax-flex ax-gap-2 ax-items-center" *ngIf="card">
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
            <div>{{ card.delivery.recipient_phone || '—' }}</div>
            <span *ngIf="card.delivery.sms_delivered_at" class="ax-text-sm ax-text-secondary">{{ fmtDate(card.delivery.sms_delivered_at) }}</span>
          </div>
          <div class="gc-cell" *ngIf="card.delivery.scheduled_at">
            <span class="ax-field-label">{{ 'gift_cards_admin.delivery_scheduled' | translate }}</span>
            <div>{{ fmtDate(card.delivery.scheduled_at) }}</div>
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
          <select class="ax-select" [(ngModel)]="adjustForm.type">
            <option value="credit">{{ 'gift_cards_admin.adjust_credit' | translate }}</option>
            <option value="debit">{{ 'gift_cards_admin.adjust_debit' | translate }}</option>
          </select>
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
  private readonly adapter = inject(PortalCrudAdapter);
  private readonly toast = inject(HotToastService);
  private readonly confirm = inject(AxConfirmService);

  id = '';
  card: any = null;
  loading = false;

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
      error: () => {
        this.card = null;
        this.loading = false;
        this.toast.error('Unable to load gift card.');
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

  goBack() { this.router.navigate(['/admin-gift-cards']); }
}
