import {
  Component,
  ChangeDetectionStrategy,
  Input,
  forwardRef,
  signal,
  computed,
  ChangeDetectorRef,
  inject,
} from '@angular/core';
import { NgFor, NgIf } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR, NG_VALIDATORS, Validator, AbstractControl, ValidationErrors } from '@angular/forms';

/**
 * Phone input — E.164 entry with a country-code selector.
 *
 * Scope (Y.1)
 * -----------
 * The country list is intentionally curated for the GCC + ~25 tourist
 * origin markets rather than every ISO 3166-1 country. Trade-off: lighter
 * bundle than a full libphonenumber dataset, and the dropdown stays
 * short enough to navigate quickly. Adding a country is a one-line
 * change in COUNTRIES below.
 *
 * Y.4 will swap in libphonenumber-js (~150 KB lazy-loaded) once
 * non-GCC vendor onboarding requires it.
 *
 * Output shape
 * ------------
 * The control's outer value is the FULL E.164 string with leading '+',
 * e.g. '+971501234567'. Matches the apps/api RegisterInput contract
 * exactly.
 *
 * Validation
 * ----------
 * Runs alongside any consumer-supplied validators. Returns:
 *   { phoneInvalid: true } when the national-digits portion doesn't
 *   match the selected country's pattern.
 *   { required: true } when no digits entered (only emitted if the
 *   consumer also attached Validators.required — we don't mark on our
 *   own; leaving emptiness to required gives consumers control).
 *
 * Implementation note: NG_VALIDATORS forwardRef cycle
 * ---------------------------------------------------
 * Because we both implement ControlValueAccessor AND Validator AND
 * have a self-referencing forwardRef, the class must be declared
 * before the multi-providers reference it. forwardRef(() => …) handles
 * the temporal cycle.
 */

export interface PhoneCountry {
  /** ISO 3166-1 alpha-2 code, e.g. 'AE'. */
  code: string;
  /** Calling code with leading '+', e.g. '+971'. */
  dialCode: string;
  /** Display name (English). For Y.1 we don't translate country names
   *  individually; using full English is acceptable in Arabic UI per
   *  X.7 register choice. */
  name: string;
  /** Flag emoji — pure presentation, optional. */
  flag: string;
  /** Min and max national-digit lengths for validation. */
  nationalDigits: { min: number; max: number };
}

/**
 * Curated GCC + tourist-origin country list. Default first (UAE).
 * Sourced from common GSMA references; numbers are minimums for valid
 * fully-qualified subscriber numbers, exclusive of country code.
 */
export const PHONE_COUNTRIES: readonly PhoneCountry[] = [
  /* GCC */
  { code: 'AE', dialCode: '+971', name: 'United Arab Emirates', flag: '🇦🇪', nationalDigits: { min: 8, max: 9 } },
  { code: 'SA', dialCode: '+966', name: 'Saudi Arabia', flag: '🇸🇦', nationalDigits: { min: 9, max: 9 } },
  { code: 'KW', dialCode: '+965', name: 'Kuwait', flag: '🇰🇼', nationalDigits: { min: 8, max: 8 } },
  { code: 'BH', dialCode: '+973', name: 'Bahrain', flag: '🇧🇭', nationalDigits: { min: 8, max: 8 } },
  { code: 'QA', dialCode: '+974', name: 'Qatar', flag: '🇶🇦', nationalDigits: { min: 8, max: 8 } },
  { code: 'OM', dialCode: '+968', name: 'Oman', flag: '🇴🇲', nationalDigits: { min: 8, max: 8 } },
  /* Wider MENA */
  { code: 'EG', dialCode: '+20', name: 'Egypt', flag: '🇪🇬', nationalDigits: { min: 9, max: 10 } },
  { code: 'JO', dialCode: '+962', name: 'Jordan', flag: '🇯🇴', nationalDigits: { min: 8, max: 9 } },
  { code: 'LB', dialCode: '+961', name: 'Lebanon', flag: '🇱🇧', nationalDigits: { min: 7, max: 8 } },
  { code: 'IQ', dialCode: '+964', name: 'Iraq', flag: '🇮🇶', nationalDigits: { min: 9, max: 10 } },
  { code: 'MA', dialCode: '+212', name: 'Morocco', flag: '🇲🇦', nationalDigits: { min: 9, max: 9 } },
  { code: 'TN', dialCode: '+216', name: 'Tunisia', flag: '🇹🇳', nationalDigits: { min: 8, max: 8 } },
  /* Tourist + diaspora origins */
  { code: 'GB', dialCode: '+44', name: 'United Kingdom', flag: '🇬🇧', nationalDigits: { min: 10, max: 10 } },
  { code: 'US', dialCode: '+1', name: 'United States', flag: '🇺🇸', nationalDigits: { min: 10, max: 10 } },
  { code: 'CA', dialCode: '+1', name: 'Canada', flag: '🇨🇦', nationalDigits: { min: 10, max: 10 } },
  { code: 'IN', dialCode: '+91', name: 'India', flag: '🇮🇳', nationalDigits: { min: 10, max: 10 } },
  { code: 'PK', dialCode: '+92', name: 'Pakistan', flag: '🇵🇰', nationalDigits: { min: 10, max: 10 } },
  { code: 'PH', dialCode: '+63', name: 'Philippines', flag: '🇵🇭', nationalDigits: { min: 10, max: 10 } },
  { code: 'BD', dialCode: '+880', name: 'Bangladesh', flag: '🇧🇩', nationalDigits: { min: 10, max: 10 } },
  { code: 'LK', dialCode: '+94', name: 'Sri Lanka', flag: '🇱🇰', nationalDigits: { min: 9, max: 9 } },
  { code: 'NP', dialCode: '+977', name: 'Nepal', flag: '🇳🇵', nationalDigits: { min: 10, max: 10 } },
  { code: 'TR', dialCode: '+90', name: 'Turkey', flag: '🇹🇷', nationalDigits: { min: 10, max: 10 } },
  { code: 'DE', dialCode: '+49', name: 'Germany', flag: '🇩🇪', nationalDigits: { min: 10, max: 11 } },
  { code: 'FR', dialCode: '+33', name: 'France', flag: '🇫🇷', nationalDigits: { min: 9, max: 9 } },
  { code: 'IT', dialCode: '+39', name: 'Italy', flag: '🇮🇹', nationalDigits: { min: 9, max: 10 } },
  { code: 'ES', dialCode: '+34', name: 'Spain', flag: '🇪🇸', nationalDigits: { min: 9, max: 9 } },
  { code: 'NL', dialCode: '+31', name: 'Netherlands', flag: '🇳🇱', nationalDigits: { min: 9, max: 9 } },
  { code: 'AU', dialCode: '+61', name: 'Australia', flag: '🇦🇺', nationalDigits: { min: 9, max: 9 } },
  { code: 'CN', dialCode: '+86', name: 'China', flag: '🇨🇳', nationalDigits: { min: 11, max: 11 } },
  { code: 'JP', dialCode: '+81', name: 'Japan', flag: '🇯🇵', nationalDigits: { min: 10, max: 11 } },
];

export const DEFAULT_COUNTRY_CODE = 'AE';

/**
 * Parse an E.164 string into { country, nationalDigits }.
 *
 * Greedily picks the longest matching dial-code prefix from
 * PHONE_COUNTRIES (so '+1' US vs '+1' CA both match — we default to
 * US for that ambiguity; UI lets user re-select).
 *
 * Returns null if the input doesn't start with '+' or no country
 * matches; consumers then fall back to default country + raw digits.
 */
export function parseE164(value: string): { country: PhoneCountry; nationalDigits: string } | null {
  if (!value.startsWith('+')) return null;

  /* Sort countries by descending dialCode length so longer codes (e.g.
     '+971') win over shorter ones ('+97' doesn't exist but illustrates
     the issue). Cache the sorted list — function is hot during typing. */
  const sorted = [...PHONE_COUNTRIES].sort((a, b) => b.dialCode.length - a.dialCode.length);
  for (const country of sorted) {
    if (value.startsWith(country.dialCode)) {
      return {
        country,
        nationalDigits: value.substring(country.dialCode.length).replace(/\D/g, ''),
      };
    }
  }
  return null;
}

@Component({
  selector: 'ui-phone-input',
  standalone: true,
  imports: [NgFor, NgIf],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => PhoneInputComponent),
      multi: true,
    },
    {
      provide: NG_VALIDATORS,
      useExisting: forwardRef(() => PhoneInputComponent),
      multi: true,
    },
  ],
  template: `
    <div class="phone-input" [class.phone-input--disabled]="isDisabled()">
      <select
        class="phone-input__country"
        [attr.aria-label]="countryAriaLabel"
        [disabled]="isDisabled()"
        [value]="countryCode()"
        (change)="onCountryChange($event)"
        data-testid="phone-input-country"
      >
        <option *ngFor="let c of countries" [value]="c.code">
          {{ c.flag }} {{ c.name }} ({{ c.dialCode }})
        </option>
      </select>

      <input
        class="phone-input__national"
        type="tel"
        autocomplete="tel-national"
        inputmode="numeric"
        [id]="inputId"
        [attr.aria-label]="nationalAriaLabel"
        [placeholder]="placeholder"
        [disabled]="isDisabled()"
        [value]="nationalDigits()"
        (input)="onNationalInput($event)"
        (blur)="onTouched()"
        data-testid="phone-input-national"
      />
    </div>
  `,
  styles: [
    `
      .phone-input {
        display: flex;
        gap: 8px;
      }

      .phone-input__country {
        flex: 0 0 auto;
        max-width: 180px;
        height: 44px;
        padding: 0 12px;
        border: 1px solid var(--color-border-default, #d8cfc1);
        border-radius: 8px;
        background: #fff;
        font-size: 14px;
        color: var(--color-text-primary, #2e241c);
      }

      .phone-input__national {
        flex: 1 1 auto;
        height: 44px;
        padding: 0 14px;
        border: 1px solid var(--color-border-default, #d8cfc1);
        border-radius: 8px;
        font-size: 16px;
        color: var(--color-text-primary, #2e241c);
        font-variant-numeric: tabular-nums;
      }

      .phone-input__country:focus,
      .phone-input__national:focus {
        outline: 2px solid var(--color-brand-500, #b18f1f);
        outline-offset: 0;
        border-color: transparent;
      }

      .phone-input--disabled .phone-input__country,
      .phone-input--disabled .phone-input__national {
        background: var(--color-bg-muted, #f4f0ea);
        cursor: not-allowed;
      }

      :host-context([dir='rtl']) .phone-input__national {
        text-align: right;
      }
    `,
  ],
})
export class PhoneInputComponent implements ControlValueAccessor, Validator {
  @Input() inputId = 'phone-input';
  @Input() placeholder = '';
  @Input() countryAriaLabel = 'Country';
  @Input() nationalAriaLabel = 'Phone number';

  /** Exposed for consumers that want to render the static list elsewhere. */
  protected readonly countries = PHONE_COUNTRIES;

  /** Selected country's ISO alpha-2 code. */
  protected readonly countryCode = signal<string>(DEFAULT_COUNTRY_CODE);

  /** National digits (without dial code). */
  protected readonly nationalDigits = signal<string>('');

  /** Disabled state (set by Reactive Forms via setDisabledState). */
  protected readonly isDisabled = signal<boolean>(false);

  /** Selected country object derived from code. */
  private readonly country = computed<PhoneCountry>(() => {
    return PHONE_COUNTRIES.find(c => c.code === this.countryCode()) ?? PHONE_COUNTRIES[0];
  });

  private onChange: (value: string) => void = () => undefined;
  /** Public for the template's (blur). */
  protected onTouched: () => void = () => undefined;

  private readonly cdr = inject(ChangeDetectorRef);

  /* ----------------------------------------------------------------
     ControlValueAccessor
     ---------------------------------------------------------------- */

  writeValue(value: string | null): void {
    if (value === null || value === undefined || value === '') {
      this.nationalDigits.set('');
      /* Leave country at its existing default rather than reset on
         writeValue('') — common during form.reset(). */
      this.cdr.markForCheck();
      return;
    }
    const parsed = parseE164(value);
    if (parsed !== null) {
      this.countryCode.set(parsed.country.code);
      this.nationalDigits.set(parsed.nationalDigits);
    } else {
      /* Couldn't parse — leave digits intact, leave country at default.
         The validator will mark this invalid. */
      this.nationalDigits.set(value.replace(/\D/g, ''));
    }
    this.cdr.markForCheck();
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.isDisabled.set(isDisabled);
  }

  /* ----------------------------------------------------------------
     Validator
     ---------------------------------------------------------------- */

  validate(control: AbstractControl): ValidationErrors | null {
    const value = control.value as string | null | undefined;
    if (value === null || value === undefined || value === '') return null;
    const parsed = parseE164(value);
    if (parsed === null) return { phoneInvalid: true };
    const digits = parsed.nationalDigits;
    if (digits.length < parsed.country.nationalDigits.min) return { phoneInvalid: true };
    if (digits.length > parsed.country.nationalDigits.max) return { phoneInvalid: true };
    return null;
  }

  /* ----------------------------------------------------------------
     UI handlers
     ---------------------------------------------------------------- */

  protected onCountryChange(event: Event): void {
    const code = (event.target as HTMLSelectElement).value;
    this.countryCode.set(code);
    this.emit();
  }

  protected onNationalInput(event: Event): void {
    /* Strip everything that isn't a digit so paste-with-spaces /
       paste-with-dashes still produces a clean value. */
    const raw = (event.target as HTMLInputElement).value;
    const digits = raw.replace(/\D/g, '');
    this.nationalDigits.set(digits);
    this.emit();
  }

  /**
   * Push the composed E.164 value out. Always includes the dial code
   * even when digits is empty, so the consumer sees a deterministic
   * shape (`+971` rather than `''` when only the country is selected).
   *
   * Edge case: when digits is empty, we DON'T emit a partial value;
   * we emit '' so Validators.required works as expected. The user
   * "hasn't typed a phone number" until they enter digits.
   */
  private emit(): void {
    const digits = this.nationalDigits();
    if (digits === '') {
      this.onChange('');
      return;
    }
    this.onChange(`${this.country().dialCode}${digits}`);
  }
}
