import {
  Component,
  ChangeDetectionStrategy,
  Input,
  Output,
  EventEmitter,
  inject,
  signal,
  OnInit,
  OnDestroy,
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
import { PlacesService } from '../../core/places';
import type { PlaceSuggestion } from '../../core/places';
import {
  FormFieldComponent,
  PhoneInputComponent,
  mapApiErrors,
  ApiErrorMapping,
  ToastService,
} from '../../shared/forms';

/**
 * AddressFormComponent, the create/edit form for an address.
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
 *     is_default toggle is intentionally hidden in edit mode, to
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
        <!-- street_address has live Google Places autocomplete wired to it.
             When the API key is missing (placesAvailable === false) the
             dropdown logic is inert and this stays a plain text field. -->
        <div class="addr-street-ac">
          <input
            id="addr-street"
            type="text"
            autocomplete="off"
            formControlName="street_address"
            class="auth-input"
            data-testid="addr-street"
            [attr.role]="placesAvailable ? 'combobox' : null"
            [attr.aria-expanded]="placesAvailable ? streetOpen() : null"
            [attr.aria-autocomplete]="placesAvailable ? 'list' : null"
            (input)="onStreetInput($event)"
            (focus)="onStreetFocus()"
            (blur)="onStreetBlur()"
          />

          <ul
            *ngIf="placesAvailable && streetOpen() && streetSuggestions().length > 0"
            class="addr-street-ac__list"
            role="listbox"
            data-testid="addr-street-suggestions"
          >
            <li
              *ngFor="let s of streetSuggestions()"
              class="addr-street-ac__item"
              role="option"
              [attr.aria-selected]="false"
              (mousedown)="onSelectSuggestion(s, $event)"
            >
              <span class="addr-street-ac__main">{{ s.mainText }}</span>
              <span class="addr-street-ac__secondary" *ngIf="s.secondaryText">{{
                s.secondaryText
              }}</span>
            </li>
          </ul>
        </div>
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
export class AddressFormComponent implements OnInit, OnDestroy {
  /** Existing address for edit mode; null for create. */
  @Input() address: Address | null = null;

  @Output() saved = new EventEmitter<Address>();
  @Output() cancelled = new EventEmitter<void>();

  protected readonly emirates = UAE_EMIRATES;
  protected readonly submitting = signal(false);

  /* ----- Google Places autocomplete (street_address only) ----- */
  private readonly places = inject(PlacesService);
  /** Whether the Places API key is configured. When false the dropdown
      logic is inert and the street field is a plain text input. */
  protected readonly placesAvailable = this.places.isAvailable;
  /** Current suggestions driving the dropdown. */
  protected readonly streetSuggestions = signal<PlaceSuggestion[]>([]);
  /** Whether the dropdown is visible (focused + has results). */
  protected readonly streetOpen = signal(false);

  /** Debounce timer for autocomplete requests. */
  private streetDebounce: ReturnType<typeof setTimeout> | null = null;
  /** Most recent query sent, used to drop stale responses. */
  private streetLastQuery = '';
  /** True while a details fetch is in flight after a selection, keeps
      blur from closing the dropdown before the mousedown handler runs. */
  private streetFetchingDetails = false;

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

  ngOnDestroy(): void {
    if (this.streetDebounce !== null) {
      clearTimeout(this.streetDebounce);
    }
  }

  /* ----- Street autocomplete handlers ----- */

  /** User typed in the street field. The reactive FormControl already
      reflects the value via formControlName; here we additionally drive
      the Places autocomplete dropdown. */
  protected onStreetInput(event: Event): void {
    if (!this.placesAvailable) return;

    const text = (event.target as HTMLInputElement).value;
    /* Begin a session on the first keystroke of an interaction. The
       service starts one lazily too, but calling it here keeps the
       session aligned with the user's typing as the mobile form does. */
    if (text.trim().length > 0 && this.streetLastQuery === '') {
      this.places.startSession();
    }

    if (this.streetDebounce !== null) {
      clearTimeout(this.streetDebounce);
    }
    /* 250ms balances responsiveness against per-keystroke quota. */
    this.streetDebounce = setTimeout(() => this.fetchStreetSuggestions(text), 250);
  }

  protected onStreetFocus(): void {
    if (this.streetSuggestions().length > 0) {
      this.streetOpen.set(true);
    }
  }

  protected onStreetBlur(): void {
    /* A mousedown on a suggestion is handled before blur closes the list;
       but if a details fetch is in flight, keep it open until it lands. */
    if (this.streetFetchingDetails) return;
    /* Delay so a suggestion mousedown can register before we hide. */
    setTimeout(() => this.streetOpen.set(false), 150);
  }

  private async fetchStreetSuggestions(input: string): Promise<void> {
    this.streetLastQuery = input;
    if (!input || input.trim().length < 2) {
      this.streetSuggestions.set([]);
      this.streetOpen.set(false);
      return;
    }

    const results = await this.places.autocomplete(input);
    /* Drop stale responses if a newer query has since been issued. */
    if (this.streetLastQuery !== input) return;

    this.streetSuggestions.set(results);
    this.streetOpen.set(results.length > 0);
  }

  /** User picked a suggestion. Resolve details and patch street_address
      with the resolved street (preferring Google's parsed street, then the
      suggestion's main text, then the formatted address). Emirate + area
      are intentionally left user-controlled. */
  protected async onSelectSuggestion(s: PlaceSuggestion, event: Event): Promise<void> {
    /* mousedown fires before blur, prevent default so the input keeps
       focus and the blur-close race never starts. */
    event.preventDefault();

    this.streetFetchingDetails = true;
    /* Immediate feedback: show the main line while details load. */
    this.form.controls.street_address.setValue(s.mainText);
    this.streetSuggestions.set([]);
    this.streetOpen.set(false);

    const details = await this.places.details(s.placeId);
    this.streetFetchingDetails = false;
    this.streetLastQuery = '';

    const resolved = details?.street ?? s.mainText ?? details?.formattedAddress ?? '';
    if (resolved) {
      this.form.controls.street_address.setValue(resolved);
      this.form.controls.street_address.markAsDirty();
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
