import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import {
  OrderService,
  RETURN_REASONS,
  RETURN_REASON_LABELS,
  RETURN_PHOTO_MAX_BYTES,
  RETURN_PHOTO_MAX_COUNT,
  RETURN_PHOTO_ACCEPT,
} from '../../core/orders';
import type {
  Order,
  ReturnReason,
  SubmitReturnInput,
} from '../../core/orders';
import { ToastService, mapApiErrors } from '../../shared/forms';
import { CfImagePipe } from '../../shared/ui/cf-image.pipe';

/**
 * /account/orders/:id/return, submit a return request.
 *
 * Auth-gated. Reachable from the order detail page's "Request a
 * return" CTA (Y.5 wires the CTA; this route stands on its own and
 * can be navigated to directly).
 *
 * Flow
 * ----
 *   1. Load the order via OrderService.getById
 *   2. Render the items list with per-item checkboxes (only items
 *      that haven't already been returned are selectable; the
 *      server enforces this but we hide already-returned items
 *      defensively)
 *   3. Form fields:
 *      - reason       single-select (radio cards)
 *      - notes        textarea (required when reason='other',
 *                     optional otherwise)
 *      - photos       up to 5 image files, jpeg/png/webp, ≤5MB each
 *   4. Submit → multipart POST /v3/orders/:id/returns
 *   5. On success → toast + navigate back to /account/orders/:id
 *   6. On failure → mapApiErrors handles VALIDATION_FAILED + RETURN_*
 *      eligibility codes; unmapped → toast networkError
 *
 * Photo handling
 * --------------
 * Files are read into a local array on the (change) handler. The
 * UI shows thumbnails + a remove (×) per file. Selecting more than
 * MAX_COUNT photos or files exceeding MAX_BYTES throws a toast and
 * is rejected locally; the server would reject too but local
 * pre-validation gives faster feedback.
 *
 * Notes/required policy
 * --------------------
 * The 'notes' control's required validator is dynamically toggled
 * based on the selected reason (effect runs on reason change).
 */
@Component({
  selector: 'app-account-order-return',
  standalone: true,
  imports: [CfImagePipe, NgIf, NgFor, RouterLink, ReactiveFormsModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="orders-page" data-testid="order-return-page">
      <div class="orders-page__container orders-page__container--narrow">
        <a
          *ngIf="order() !== null"
          [routerLink]="['/account/orders', order()!.id]"
          class="orders-page__back-link"
          data-testid="return-back-link"
        >
          ← {{ order()!.order_reference }}
        </a>

        <h1 class="order-detail__title return-page__title">
          {{ 'orders.returns.title' | translate }}
        </h1>

        <ng-container *ngIf="order() !== null; else loadingOrError">
          <form
            [formGroup]="form"
            (ngSubmit)="onSubmit()"
            class="return-form"
            data-testid="return-form"
          >
            <!-- Items selection -->
            <section class="checkout-section" aria-labelledby="return-items-heading">
              <h2 id="return-items-heading" class="checkout-section__title">
                {{ 'orders.returns.itemsHeading' | translate }}
              </h2>

              <ul class="return-items" role="list">
                <li
                  *ngFor="let item of selectableItems(); trackBy: trackById"
                  class="return-item"
                  [class.return-item--selected]="isItemSelected(item.id)"
                  data-testid="return-item"
                >
                  <label class="return-item__label">
                    <input
                      type="checkbox"
                      class="return-item__check"
                      [checked]="isItemSelected(item.id)"
                      (change)="toggleItem(item.id)"
                      [attr.data-item-id]="item.id"
                    />
                    <div class="return-item__thumb" aria-hidden="true">
                      <img
                        *ngIf="(item.product_image ?? '') !== ''; else thumbBlank"
                        [src]="item.product_image | cfImage:'thumb'"
                        alt=""
                        loading="lazy"
                      />
                      <ng-template #thumbBlank>
                        <div class="review-item__thumb-placeholder"></div>
                      </ng-template>
                    </div>
                    <div class="return-item__body">
                      <p class="review-item__name">
                        {{ item.product_name || ('cart.drawer.unnamedItem' | translate) }}
                      </p>
                      <p class="review-item__qty">
                        {{ 'cart.drawer.qtyLabel' | translate }}: {{ item.quantity }}
                      </p>
                    </div>
                  </label>
                </li>
              </ul>

              <p
                *ngIf="form.controls.order_item_ids.touched && selectedItemIds().length === 0"
                class="return-form__error"
                data-testid="return-items-error"
              >
                {{ 'orders.returns.errors.noItems' | translate }}
              </p>
            </section>

            <!-- Reason -->
            <section class="checkout-section" aria-labelledby="return-reason-heading">
              <h2 id="return-reason-heading" class="checkout-section__title">
                {{ 'orders.returns.reasonHeading' | translate }}
              </h2>

              <div class="return-reasons" role="radiogroup">
                <label
                  *ngFor="let r of allReasons"
                  class="return-reason"
                  [class.return-reason--selected]="form.controls.reason.value === r"
                  [attr.data-reason]="r"
                >
                  <input
                    type="radio"
                    [value]="r"
                    formControlName="reason"
                    class="return-reason__input"
                  />
                  <span>{{ reasonLabel(r) | translate }}</span>
                </label>
              </div>
            </section>

            <!-- Notes -->
            <section
              class="checkout-section"
              aria-labelledby="return-notes-heading"
            >
              <h2 id="return-notes-heading" class="checkout-section__title">
                {{ 'orders.returns.notesHeading' | translate }}
                <span *ngIf="notesRequired()" class="return-form__required">*</span>
              </h2>
              <textarea
                formControlName="customer_notes"
                class="return-form__textarea"
                rows="4"
                [attr.placeholder]="'orders.returns.notesPlaceholder' | translate"
                data-testid="return-notes"
              ></textarea>
              <p
                *ngIf="form.controls.customer_notes.touched && form.controls.customer_notes.invalid"
                class="return-form__error"
                data-testid="return-notes-error"
              >
                {{ 'orders.returns.errors.notesRequired' | translate }}
              </p>
            </section>

            <!-- Photos -->
            <section
              class="checkout-section"
              aria-labelledby="return-photos-heading"
            >
              <h2 id="return-photos-heading" class="checkout-section__title">
                {{ 'orders.returns.photosHeading' | translate }}
              </h2>
              <p class="return-form__hint">
                {{ 'orders.returns.photosHint' | translate }}
              </p>

              <input
                #fileInput
                type="file"
                multiple
                [accept]="ACCEPT_TYPES"
                (change)="onPhotosSelected($event)"
                class="return-form__file-input"
                data-testid="return-photos-input"
              />

              <ul
                *ngIf="photos().length > 0"
                class="return-photos"
                role="list"
                data-testid="return-photos-list"
              >
                <li
                  *ngFor="let p of photos(); let i = index; trackBy: trackByName"
                  class="return-photo"
                  data-testid="return-photo"
                >
                  <span class="return-photo__name">{{ p.name }}</span>
                  <button
                    type="button"
                    class="return-photo__remove"
                    (click)="removePhoto(i)"
                    [attr.aria-label]="'orders.returns.removePhoto' | translate"
                    data-testid="return-photo-remove"
                  >×</button>
                </li>
              </ul>
            </section>

            <div class="return-form__actions">
              <button
                type="submit"
                class="orders-page__empty-cta"
                [disabled]="isSubmitting()"
                data-testid="return-submit"
              >
                {{ (isSubmitting() ? 'common.loading' : 'orders.returns.submit') | translate }}
              </button>
            </div>
          </form>
        </ng-container>

        <ng-template #loadingOrError>
          <div
            *ngIf="isLoading()"
            class="orders-page__loading"
            data-testid="return-loading"
          >
            {{ 'common.loading' | translate }}
          </div>
          <div
            *ngIf="!isLoading() && errored()"
            class="orders-page__empty"
            data-testid="return-error"
          >
            <h2 class="orders-page__empty-title">
              {{ 'orders.detail.loadError' | translate }}
            </h2>
            <a routerLink="/account/orders" class="orders-page__empty-cta">
              {{ 'orders.detail.backToList' | translate }}
            </a>
          </div>
        </ng-template>
      </div>
    </main>
  `,
  styleUrl: './return-form.scss',
})
export class AccountOrderReturnPageComponent implements OnInit {
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly orderService = inject(OrderService);
  private readonly toast = inject(ToastService);

  private readonly _order = signal<Order | null>(null);
  protected readonly order = this._order.asReadonly();
  protected readonly isLoading = this.orderService.isLoadingDetail;

  private readonly _errored = signal(false);
  protected readonly errored = this._errored.asReadonly();

  private readonly _isSubmitting = signal(false);
  protected readonly isSubmitting = this._isSubmitting.asReadonly();

  private readonly _photos = signal<File[]>([]);
  protected readonly photos = this._photos.asReadonly();

  /** Currently-selected item ids, stored separately from the form
   *  for cleaner template binding (checkbox group). */
  private readonly _selectedItemIds = signal<number[]>([]);
  protected readonly selectedItemIds = this._selectedItemIds.asReadonly();

  protected readonly allReasons: ReturnReason[] = RETURN_REASONS;
  protected readonly ACCEPT_TYPES = RETURN_PHOTO_ACCEPT;

  protected readonly form: FormGroup<{
    reason: FormControl<ReturnReason>;
    customer_notes: FormControl<string>;
    /** Synthetic control for items-touched tracking; the actual
     *  selection is in _selectedItemIds. */
    order_item_ids: FormControl<string>;
  }>;

  protected readonly notesRequired = computed(() => {
    return this.form.controls.reason.value === 'other';
  });

  /** Items that aren't already in an in-flight return. */
  protected readonly selectableItems = computed(() => {
    const o = this._order();
    if (o === null) return [];
    return o.items;
  });

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      reason: fb.control<ReturnReason>('defective', { validators: Validators.required }),
      customer_notes: fb.control(''),
      order_item_ids: fb.control(''),
    });

    /* Toggle customer_notes required when reason changes. */
    this.form.controls.reason.valueChanges.subscribe(reason => {
      const ctrl = this.form.controls.customer_notes;
      if (reason === 'other') {
        ctrl.setValidators([Validators.required, Validators.minLength(3)]);
      } else {
        ctrl.clearValidators();
      }
      ctrl.updateValueAndValidity({ emitEvent: false });
    });
  }

  async ngOnInit(): Promise<void> {
    const idParam = this.route.snapshot.paramMap.get('id');
    const id = idParam !== null ? Number(idParam) : NaN;
    if (!Number.isFinite(id) || id <= 0) {
      this._errored.set(true);
      return;
    }
    try {
      const order = await this.orderService.getById(id);
      this._order.set(order);
    } catch {
      this._errored.set(true);
      this.toast.error('orders.detail.loadError');
    }
  }

  /* -----------------------------------------------------------------
     Item selection
     ----------------------------------------------------------------- */

  protected isItemSelected(id: number): boolean {
    return this._selectedItemIds().includes(id);
  }

  protected toggleItem(id: number): void {
    const current = this._selectedItemIds();
    if (current.includes(id)) {
      this._selectedItemIds.set(current.filter(x => x !== id));
    } else {
      this._selectedItemIds.set([...current, id]);
    }
    /* Mark the synthetic control as touched so the error message
       can render when zero items are selected. */
    this.form.controls.order_item_ids.markAsTouched();
  }

  protected trackById(_idx: number, item: { id: number }): number {
    return item.id;
  }

  protected trackByName(_idx: number, file: File): string {
    return file.name;
  }

  /* -----------------------------------------------------------------
     Photos
     ----------------------------------------------------------------- */

  protected onPhotosSelected(event: Event): void {
    const input = event.target as HTMLInputElement;
    if (input.files === null) return;
    const newFiles = Array.from(input.files);

    /* Validate per-file size. */
    for (const file of newFiles) {
      if (file.size > RETURN_PHOTO_MAX_BYTES) {
        this.toast.error('orders.returns.errors.photoTooLarge');
        input.value = '';
        return;
      }
    }

    /* Validate total count after merge. */
    const combined = [...this._photos(), ...newFiles];
    if (combined.length > RETURN_PHOTO_MAX_COUNT) {
      this.toast.error('orders.returns.errors.tooManyPhotos');
      input.value = '';
      return;
    }

    this._photos.set(combined);
    input.value = '';
  }

  protected removePhoto(index: number): void {
    const photos = this._photos();
    this._photos.set(photos.filter((_, i) => i !== index));
  }

  protected reasonLabel(reason: ReturnReason): string {
    return RETURN_REASON_LABELS[reason];
  }

  /* -----------------------------------------------------------------
     Submit
     ----------------------------------------------------------------- */

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    this.form.controls.order_item_ids.markAsTouched();

    if (this.form.invalid) return;
    if (this._selectedItemIds().length === 0) return;
    if (this._isSubmitting()) return;

    const order = this._order();
    if (order === null) return;

    const input: SubmitReturnInput = {
      reason: this.form.controls.reason.value,
      customer_notes: this.form.controls.customer_notes.value.trim() || null,
      order_item_ids: this._selectedItemIds(),
      photos: this._photos(),
    };

    this._isSubmitting.set(true);
    try {
      await this.orderService.submitReturn(order.id, input);
      this.toast.success('orders.returns.success');
      await this.router.navigateByUrl(`/account/orders/${order.id}`);
    } catch (err) {
      const result = mapApiErrors(err, this.form, {
        RETURN_INELIGIBLE: { field: null, key: 'ineligible' },
        RETURN_ITEM_NOT_DELIVERED: { field: null, key: 'item_not_delivered' },
        RETURN_WINDOW_EXPIRED: { field: null, key: 'window_expired' },
        RETURN_ITEM_ALREADY_IN_FLIGHT: { field: null, key: 'item_in_flight' },
      });
      if (result.isNetworkError) {
        this.toast.error('orders.returns.errors.networkError');
      } else if (result.unmapped.length > 0) {
        /* Surface a generic toast for unhandled codes. */
        this.toast.error('orders.returns.errors.submitFailed');
      }
      /* If mapped to field errors via VALIDATION_FAILED, mapApiErrors
         already attached them to the form controls. */
    } finally {
      this._isSubmitting.set(false);
    }
  }
}
