/**
 * AxTextFieldComponent — abayti's text input primitive.
 *
 * M2-styled input with:
 *   - Label above the input (required, with optional asterisk for required fields)
 *   - Native <input> with M2 border, focus ring (brown), 48px height
 *   - Optional helper or error text below
 *   - Built-in password visibility toggle when type="password"
 *   - Optional multiline mode (renders <textarea> instead of <input>)
 *   - ControlValueAccessor for [(ngModel)] / FormControl support
 *
 * Usage:
 *
 *   <ax-text-field
 *     label="Email address"
 *     type="email"
 *     [required]="true"
 *     autocomplete="email"
 *     placeholder="you@example.com"
 *     [(ngModel)]="email"
 *     name="email">
 *   </ax-text-field>
 *
 *   <ax-text-field
 *     label="Password"
 *     type="password"
 *     [required]="true"
 *     autocomplete="current-password"
 *     [(ngModel)]="password"
 *     name="password">
 *   </ax-text-field>
 *
 *   <ax-text-field
 *     label="Phone"
 *     type="tel"
 *     inputmode="numeric"
 *     [maxlength]="10"
 *     hint="10 digits, no spaces"
 *     [(ngModel)]="phone"
 *     name="phone">
 *   </ax-text-field>
 *
 *   <!-- Multiline / textarea mode -->
 *   <ax-text-field
 *     label="Tell us more"
 *     [multiline]="true"
 *     [rows]="4"
 *     placeholder="Optional notes…"
 *     [(ngModel)]="notes"
 *     name="notes">
 *   </ax-text-field>
 *
 * See docs/m8-auth-input-spec.md for design rationale (TBD).
 */

import {
  ChangeDetectionStrategy,
  Component,
  forwardRef,
  Input,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { AxIconComponent } from '../icon';
import type {
  AxTextFieldInputMode,
  AxTextFieldType,
} from './ax-text-field.types';

let instanceCounter = 0;

@Component({
  selector: 'ax-text-field',
  standalone: true,
  imports: [CommonModule, AxIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-text-field.component.html',
  styleUrl: './ax-text-field.component.scss',
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxTextFieldComponent),
      multi: true,
    },
  ],
})
export class AxTextFieldComponent implements ControlValueAccessor {
  /** Visible label above the input. Required for accessibility. */
  @Input({ required: true }) label!: string;

  /** Input type. Defaults to 'text'. Use 'password' to enable visibility toggle. */
  @Input() type: AxTextFieldType = 'text';

  /** Placeholder text. */
  @Input() placeholder = '';

  /** Show required asterisk + add native required attribute. */
  @Input() required = false;

  /** Disable the input. */
  @Input() disabled = false;

  /** Helper text shown below the input. Hidden when `error` is set. */
  @Input() hint?: string;

  /** Error message. When present, field renders in error state. */
  @Input() error?: string;

  /** Native autocomplete (e.g. 'email', 'current-password', 'one-time-code'). */
  @Input() autocomplete?: string;

  /** Native inputmode hint (e.g. 'numeric' for OTP). */
  @Input() inputmode?: AxTextFieldInputMode;

  /** Maximum length. */
  @Input() maxlength?: number;

  /** Native name attribute. Used by browser autofill. */
  @Input() name?: string;

  /**
   * Render as a <textarea> instead of <input>. When true:
   *   - The `type` prop is ignored (textareas don't have a type)
   *   - The password toggle is suppressed
   *   - Height is determined by `rows`, with manual vertical resize allowed
   */
  @Input() multiline = false;

  /** Visible row count when `multiline` is true. Defaults to 3. */
  @Input() rows = 3;

  /**
   * Stable ID generated once per instance, used to wire <label for>.
   * Prefixed to avoid collisions if multiple instances render.
   */
  readonly id = `ax-tf-${++instanceCounter}`;

  /** Internal: tracks password visibility toggle state. */
  passwordVisible = false;

  /** Internal: current value held by the control. */
  value = '';

  /**
   * Internal: forms callback registered via registerOnChange.
   * Called with the new value whenever the input changes.
   */
  private onChange: (value: string) => void = () => {};

  /**
   * Internal: forms callback registered via registerOnTouched.
   * Called when the input loses focus.
   */
  private onTouched: () => void = () => {};

  /** Computed effective input type — flips to 'text' when password is unmasked. */
  get effectiveType(): string {
    if (this.type === 'password' && this.passwordVisible) {
      return 'text';
    }
    return this.type;
  }

  /** True when this input has a password visibility toggle. */
  get hasPasswordToggle(): boolean {
    return this.type === 'password' && !this.multiline;
  }

  /** Aria label for the password toggle, switching with state. */
  get passwordToggleLabel(): string {
    return this.passwordVisible ? 'Hide password' : 'Show password';
  }

  /** Icon name for the password toggle, switching with state. */
  get passwordToggleIcon(): 'eye' | 'eye-off' {
    return this.passwordVisible ? 'eye-off' : 'eye';
  }

  // --- ControlValueAccessor -----------------------------------------------

  writeValue(value: string | null | undefined): void {
    this.value = value ?? '';
  }

  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }

  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }

  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
  }

  // --- Internal handlers --------------------------------------------------

  /** Called on every keystroke. Works for both <input> and <textarea>. */
  handleInput(event: Event): void {
    const target = event.target as HTMLInputElement | HTMLTextAreaElement;
    this.value = target.value;
    this.onChange(this.value);
  }

  /** Called when the input loses focus. */
  handleBlur(): void {
    this.onTouched();
  }

  /** Toggle password visibility. */
  togglePasswordVisibility(event: Event): void {
    event.preventDefault();
    event.stopPropagation();
    this.passwordVisible = !this.passwordVisible;
  }
}
