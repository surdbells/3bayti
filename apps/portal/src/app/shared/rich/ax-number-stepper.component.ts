import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  EventEmitter,
  Input,
  Output,
  forwardRef,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

/**
 * Number input with increment/decrement buttons.
 *
 *   <app-ax-number-stepper [(ngModel)]="quantity" [min]="1" [max]="99">
 *   </app-ax-number-stepper>
 *
 * For cart quantity, product variant count, tax rate fields, etc. Min/max
 * bounds are enforced both through the buttons and when the user types
 * directly into the input.
 */
@Component({
  selector: 'app-ax-number-stepper',
  standalone: true,
  imports: [CommonModule, FormsModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxNumberStepperComponent),
      multi: true,
    },
  ],
  template: `
    <div class="ax-input-stepper" [class.ax-input-stepper-sm]="size === 'sm'">
      <button
        type="button"
        class="ax-input-stepper-btn"
        [disabled]="disabled || value <= min"
        (click)="decrement()"
        aria-label="Decrease"
      >
        <span class="material-symbols-outlined" aria-hidden="true">remove</span>
      </button>
      <input
        type="number"
        class="ax-input-stepper-field"
        [value]="value"
        [min]="min"
        [max]="max"
        [step]="step"
        [disabled]="disabled"
        [attr.aria-label]="ariaLabel"
        (input)="onInput($event)"
        (blur)="onBlur()"
      />
      <button
        type="button"
        class="ax-input-stepper-btn"
        [disabled]="disabled || value >= max"
        (click)="increment()"
        aria-label="Increase"
      >
        <span class="material-symbols-outlined" aria-hidden="true">add</span>
      </button>
    </div>
  `,
})
export class AxNumberStepperComponent implements ControlValueAccessor {
  @Input() value = 0;
  @Input() min = 0;
  @Input() max = Number.MAX_SAFE_INTEGER;
  @Input() step = 1;
  @Input() size: 'sm' | 'md' = 'md';
  @Input() disabled = false;
  @Input() ariaLabel = 'Number input';

  @Output() valueChange = new EventEmitter<number>();

  private readonly cdr = inject(ChangeDetectorRef);
  private onChange: (v: number) => void = () => {};
  private onTouched: () => void = () => {};

  decrement(): void {
    this.setValue(this.value - this.step);
  }

  increment(): void {
    this.setValue(this.value + this.step);
  }

  onInput(event: Event): void {
    const input = event.target as HTMLInputElement;
    const raw = input.value;
    if (raw === '') {
      // Don't coerce to 0 yet — let the user finish typing
      return;
    }
    const parsed = Number(raw);
    if (!Number.isFinite(parsed)) return;
    this.setValue(parsed);
  }

  onBlur(): void {
    // Re-clamp on blur in case the user typed below min or above max
    this.setValue(this.value);
    this.onTouched();
  }

  private setValue(v: number): void {
    const clamped = Math.max(this.min, Math.min(this.max, v));
    if (clamped === this.value) return;
    this.value = clamped;
    this.valueChange.emit(clamped);
    this.onChange(clamped);
    this.cdr.markForCheck();
  }

  // ControlValueAccessor
  writeValue(value: number | null): void {
    this.value = typeof value === 'number' ? Math.max(this.min, Math.min(this.max, value)) : this.min;
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (v: number) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(disabled: boolean): void { this.disabled = disabled; this.cdr.markForCheck(); }
}
