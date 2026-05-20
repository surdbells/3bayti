import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  inject,
  signal,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import {
  FormBuilder,
  FormControl,
  FormGroup,
  ReactiveFormsModule,
  Validators,
} from '@angular/forms';
import { TranslatePipe } from '@ngx-translate/core';
import { AddressService, UAE_EMIRATES } from '../../core/addresses';
import type { Address } from '../../core/addresses';
import {
  FormFieldComponent,
  PhoneInputComponent,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../shared/forms';

/**
 * AddressFormComponent — the create/edit form for an address.
 *
 * Renders inline; consumers wrap it in a modal/sheet if they want
 * modal semantics. Two modes:
 *   - Create: @Input() address is null
 *   - Edit:   @Input() address is the existing record to pre-fill
 *
 * On submit:
 *   - Create → AddressService.create(...) then emit (saved). If
 *     is_default toggle was on, the server flips the default flag.
 *   - Edit   → AddressService.update(...) then emit (saved). The
 *     is_default toggle is intentionally hidden in edit mode — to
 *     change defaults the user uses the Set as default button on
 *     the address card (semantically cleaner: form is about address
 *     content; default-toggle is about list state).
 *
 * Emits:
 *   - (saved) on a successful submit, with the saved Address
 *   - (cancelled) when the user clicks cancel
 */
@Component({
  selector: 'app-address-form',
  standalone: true,
  imports: [
    NgIf,
    NgFor,
    ReactiveFormsModule,
    TranslatePipe,
    FormFieldComponent,
    PhoneInputComponent,
  ],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <form
      [formGroup]="form"
      (ngSubmit)="onSubmit()"
      novalidate
      class="address-form"
      data-testid="address-form"
    >
      <ui-form-field
        [label]="'addresses.form.labelLabel'"
        fieldId="addr-label"
        [helper]="'addresses.form.labelHint'"
        [control]="form.controls.label"
        [errorMap]="commonErrors"
      >
        <input
          id="addr-label"
          type="text"
          autocomplete="off"
          formControlName="label"
          class="auth-input"
          [placeholder]="'addresses.form.labelPlaceholder' | translate"
          data-testid="addr-label"
        />
      </ui-form-field>

      <div class="address-form__row-2col">
        <ui-form-field
          [label]="'addresses.form.recipientNameLabel'"
          fieldId="addr-name"
          [required]="true"
          [control]="form.controls.recipient_name"
          [errorMap]="commonErrors"
        >
          <input
            id="addr-name"
            type="text"
            autocomplete="name"
            formControlName="recipient_name"
            class="auth-input"
            data-testid="addr-name"
          />
        </ui-form-field>

        <ui-form-field
          [label]="'addresses.form.recipientPhoneLabel'"
          fieldId="addr-phone"
          [required]="true"
          [control]="form.controls.recipient_phone"
          [errorMap]="phoneErrors"
        >
          <ui-phone-input
            inputId="addr-phone"
            formControlName="recipient_phone"
            data-testid="addr-phone"
          ></ui-phone-input>
        </ui-form-field>
      </div>

      <div class="address-form__row-2col">
        <ui-form-field
          [label]="'addresses.form.emirateLabel'"
          fieldId="addr-emirate"
          [required]="true"
          [control]="form.controls.emirate"
          [errorMap]="commonErrors"
        >
          <select
            id="addr-emirate"
            formControlName="emirate"
            class="auth-input"
            data-testid="addr-emirate"
          >
            <option value="" disabled>
              {{ 'addresses.form.emiratePlaceholder' | translate }}
            </option>
            <option *ngFor="let e of emirates" [value]="e">{{ e }}</option>
          </select>
        </ui-form-field>

        <ui-form-field
          [label]="'addresses.form.areaLabel'"
          fieldId="addr-area"
          [required]="true"
          [control]="form.controls.area"
          [errorMap]="commonErrors"
        >
          <input
            id="addr-area"
            type="text"
            autocomplete="address-level2"
            formControlName="area"
            class="auth-input"
            data-testid="addr-area"
          />
        </ui-form-field>
      </div>

      <ui-form-field
        [label]="'addresses.form.streetLabel'"
        fieldId="addr-street"
        [helper]="'addresses.form.streetHint'"
        [control]="form.controls.street_address"
        [errorMap]="commonErrors"
      >
        <input
          id="addr-street"
          type="text"
          autocomplete="street-address"
          formControlName="street_address"
          class="auth-input"
          data-testid="addr-street"
        />
      </ui-form-field>

      <div class="address-form__row-2col">
        <ui-form-field
          [label]="'addresses.form.buildingLabel'"
          fieldId="addr-building"
          [control]="form.controls.building_details"
          [errorMap]="commonErrors"
        >
          <input
            id="addr-building"
            type="text"
            autocomplete="address-line2"
            formControlName="building_details"
            class="auth-input"
            data-testid="addr-building"
          />
        </ui-form-field>

        <ui-form-field
          [label]="'addresses.form.postalCodeLabel'"
          fieldId="addr-postal"
          [control]="form.controls.postal_code"
          [errorMap]="commonErrors"
        >
          <input
            id="addr-postal"
            type="text"
            autocomplete="postal-code"
            formControlName="postal_code"
            class="auth-input"
            data-testid="addr-postal"
          />
        </ui-form-field>
      </div>

      <label *ngIf="address === null" class="address-form__default-toggle">
        <input
          type="checkbox"
          formControlName="is_default"
          data-testid="addr-default"
        />
        <span>{{ 'addresses.form.setAsDefault' | translate }}</span>
      </label>

      <div class="address-form__actions">
        <button
          type="button"
          class="address-form__cancel"
          (click)="onCancel()"
          [disabled]="submitting()"
          data-testid="addr-cancel"
        >
          {{ 'common.cancel' | translate }}
        </button>
        <button
          type="submit"
          class="address-form__submit"
          [disabled]="submitting()"
          data-testid="addr-submit"
        >
          {{ (submitting() ? 'common.loading' : (address === null ? 'addresses.form.add' : 'addresses.form.save')) | translate }}
        </button>
      </div>
    </form>
  `,
  styleUrl: './address-form.scss',
})
export class AddressFormComponent implements OnInit {
  /** Existing address for edit mode; null for create. */
  @Input() address: Address | null = null;

  @Output() saved = new EventEmitter<Address>();
  @Output() cancelled = new EventEmitter<void>();

  protected readonly emirates = UAE_EMIRATES;
  protected readonly submitting = signal(false);

  protected readonly form: FormGroup<{
    label: FormControl<string>;
    recipient_name: FormControl<string>;
    recipient_phone: FormControl<string>;
    emirate: FormControl<string>;
    area: FormControl<string>;
    street_address: FormControl<string>;
    building_details: FormControl<string>;
    postal_code: FormControl<string>;
    is_default: FormControl<boolean>;
  }>;

  protected readonly commonErrors: Record<string, string> = {
    required: 'auth.fields.required',
    apiValidation: 'auth.fields.required',
  };

  protected readonly phoneErrors: Record<string, string> = {
    required: 'auth.fields.required',
    phoneInvalid: 'auth.fields.phone_invalid',
    apiValidation: 'auth.fields.phone_invalid',
  };

  private readonly addressService = inject(AddressService);
  private readonly toast = inject(ToastService);

  constructor() {
    const fb = inject(FormBuilder).nonNullable;
    this.form = fb.group({
      label: fb.control(''),
      recipient_name: fb.control('', [Validators.required]),
      recipient_phone: fb.control('', [Validators.required]),
      emirate: fb.control('', [Validators.required]),
      area: fb.control('', [Validators.required]),
      street_address: fb.control(''),
      building_details: fb.control(''),
      postal_code: fb.control(''),
      is_default: fb.control(false),
    });
  }

  ngOnInit(): void {
    if (this.address !== null) {
      this.form.patchValue({
        label: this.address.label ?? '',
        recipient_name: this.address.recipient_name,
        recipient_phone: this.address.recipient_phone,
        emirate: this.address.emirate,
        area: this.address.area,
        street_address: this.address.street_address ?? '',
        building_details: this.address.building_details ?? '',
        postal_code: this.address.postal_code ?? '',
        is_default: false, /* not editable in edit mode */
      });
    }
  }

  protected onCancel(): void {
    this.cancelled.emit();
  }

  protected async onSubmit(): Promise<void> {
    this.form.markAllAsTouched();
    if (this.form.invalid || this.submitting()) return;

    this.submitting.set(true);
    try {
      const v = this.form.value;
      /* Trim + nullify-empty for optional fields. */
      const optionalOrNull = (s: string | undefined): string | null => {
        const trimmed = (s ?? '').trim();
        return trimmed === '' ? null : trimmed;
      };

      let saved: Address;
      if (this.address === null) {
        saved = await this.addressService.create({
          recipient_name: (v.recipient_name ?? '').trim(),
          recipient_phone: v.recipient_phone ?? '',
          emirate: (v.emirate ?? '').trim(),
          area: (v.area ?? '').trim(),
          street_address: optionalOrNull(v.street_address),
          building_details: optionalOrNull(v.building_details),
          postal_code: optionalOrNull(v.postal_code),
          label: optionalOrNull(v.label),
          is_default: v.is_default === true,
        });
      } else {
        saved = await this.addressService.update(this.address.id, {
          recipient_name: (v.recipient_name ?? '').trim(),
          recipient_phone: v.recipient_phone ?? '',
          emirate: (v.emirate ?? '').trim(),
          area: (v.area ?? '').trim(),
          street_address: optionalOrNull(v.street_address),
          building_details: optionalOrNull(v.building_details),
          postal_code: optionalOrNull(v.postal_code),
          label: optionalOrNull(v.label),
        });
      }
      this.saved.emit(saved);
    } catch (err) {
      const result = mapApiErrors(err, this.form, {});
      if (result.isNetworkError) {
        this.toast.error('addresses.errors.network');
      } else if (result.unmapped.length > 0) {
        this.toast.error('addresses.errors.unexpected');
      }
      /* Per-field validation errors have already been applied to the form. */
    } finally {
      this.submitting.set(false);
    }
  }
}
