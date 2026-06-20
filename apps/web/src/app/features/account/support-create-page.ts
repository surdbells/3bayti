import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import {
  ReactiveFormsModule,
  FormBuilder,
  Validators,
} from '@angular/forms';
import { firstValueFrom } from 'rxjs';
import { TranslatePipe } from '@ngx-translate/core';
import { FormFieldComponent, ToastService, mapApiErrors } from '../../shared/forms';
import { RoutedHttpClient } from '../../core/http/routed-http-client';
import { SupportService } from './support.service';

/** Minimal store row for the optional vendor selector. */
interface StoreOption {
  id: number;
  name: string;
}

/**
 * /account/support/new — create a support ticket.
 *
 * Fields:
 *   - subject (required)
 *   - message (required, the opening message)
 *   - store/vendor (OPTIONAL — general non-store tickets are allowed
 *     since vendor_id is nullable). Stores are loaded from GET /vendors;
 *     the first option is "General support" (no vendor).
 *
 * The API (CreateMyTicketController) accepts { subject, message,
 * vendor_id? }; the service only sends vendor_id when a store is chosen.
 */
@Component({
  selector: 'app-support-create',
  standalone: true,
  imports: [
    NgIf,
    NgFor,
    RouterLink,
    ReactiveFormsModule,
    TranslatePipe,
    FormFieldComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="support-create" data-testid="support-create-page">
      <div class="support-create__container">
        <nav class="support-create__breadcrumb">
          <a routerLink="/account">{{ 'account.hub.greeting' | translate }}</a>
          <span aria-hidden="true">/</span>
          <a routerLink="/account/support">{{ 'account.support.title' | translate }}</a>
          <span aria-hidden="true">/</span>
          <span>{{ 'account.support.create.title' | translate }}</span>
        </nav>

        <h1 class="support-create__title">{{ 'account.support.create.title' | translate }}</h1>
        <p class="support-create__intro">{{ 'account.support.create.intro' | translate }}</p>

        <form
          class="support-create__form"
          [formGroup]="form"
          (ngSubmit)="onSubmit()"
          novalidate
        >
          <ui-form-field
            [label]="'account.support.create.subject'"
            fieldId="ticket-subject"
            [control]="form.controls.subject"
            [errorMap]="fieldErrors"
          >
            <input
              id="ticket-subject"
              type="text"
              maxlength="200"
              formControlName="subject"
              class="auth-input"
              data-testid="ticket-subject"
            />
          </ui-form-field>

          <ui-form-field
            [label]="'account.support.create.store'"
            fieldId="ticket-store"
            [helper]="'account.support.create.storeHint'"
            [control]="form.controls.vendorId"
            [errorMap]="fieldErrors"
          >
            <select
              id="ticket-store"
              formControlName="vendorId"
              class="auth-input"
              data-testid="ticket-store"
            >
              <option value="">{{ 'account.support.create.general' | translate }}</option>
              <option *ngFor="let s of stores()" [value]="s.id">{{ s.name }}</option>
            </select>
          </ui-form-field>

          <ui-form-field
            [label]="'account.support.create.message'"
            fieldId="ticket-message"
            [control]="form.controls.message"
            [errorMap]="fieldErrors"
          >
            <textarea
              id="ticket-message"
              rows="6"
              formControlName="message"
              class="auth-input support-create__message"
              [placeholder]="'account.support.create.messagePlaceholder' | translate"
              data-testid="ticket-message"
            ></textarea>
          </ui-form-field>

          <div class="support-create__actions">
            <a routerLink="/account/support" class="support-create__cancel">
              {{ 'common.cancel' | translate }}
            </a>
            <button
              type="submit"
              class="support-create__submit"
              [disabled]="isSaving()"
              data-testid="ticket-submit"
            >
              {{ (isSaving() ? 'common.saving' : 'account.support.create.submit') | translate }}
            </button>
          </div>
        </form>
      </div>
    </main>
  `,
  styleUrl: './support.scss',
})
export class SupportCreatePageComponent implements OnInit {
  private readonly support = inject(SupportService);
  private readonly http = inject(RoutedHttpClient);
  private readonly toast = inject(ToastService);
  private readonly router = inject(Router);

  protected readonly isSaving = this.support.isSaving;

  private readonly _stores = signal<StoreOption[]>([]);
  protected readonly stores = this._stores.asReadonly();

  protected readonly fieldErrors: Record<string, string> = {
    required: 'account.support.errors.required',
    apiValidation: 'account.support.errors.invalid',
    maxlength: 'account.support.errors.tooLong',
  };

  protected readonly form = inject(FormBuilder).nonNullable.group({
    subject: ['', [Validators.required, Validators.maxLength(200)]],
    vendorId: [''],
    message: ['', [Validators.required, Validators.maxLength(5000)]],
  });

  async ngOnInit(): Promise<void> {
    /* Best-effort store list for the optional selector. A failure here
       must not block ticket creation — general tickets need no store. */
    try {
      const env = await firstValueFrom(
        this.http.get<StoreOption[]>('GET /vendors', { query: { limit: 100 } }),
      );
      const stores = (env.data ?? [])
        .filter((s) => typeof s.id === 'number' && !!s.name)
        .map((s) => ({ id: s.id, name: s.name }));
      this._stores.set(stores);
    } catch {
      /* swallow — selector simply shows "General support" only. */
    }
  }

  protected async onSubmit(): Promise<void> {
    if (this.isSaving()) return;
    if (this.form.invalid) {
      this.form.markAllAsTouched();
      return;
    }

    const raw = this.form.getRawValue();
    const vendorIdNum = raw.vendorId === '' ? undefined : Number(raw.vendorId);

    try {
      const ticket = await this.support.createTicket({
        subject: raw.subject.trim(),
        message: raw.message.trim(),
        vendor_id: vendorIdNum,
      });
      this.toast.success('account.support.create.created');
      await this.router.navigate(['/account/support', ticket.id]);
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('common.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('account.support.create.errors.createFailed');
      }
    }
  }
}
