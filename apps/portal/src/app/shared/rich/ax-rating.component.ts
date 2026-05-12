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
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

/**
 * 5-star rating picker. SVG-based; themeable via CSS variables.
 *
 *   <app-ax-rating [(ngModel)]="rating"></app-ax-rating>
 *   <app-ax-rating [value]="4.5" readonly size="sm" [showValue]="true"></app-ax-rating>
 *
 * Half-star support: when [allowHalf]="true" (default), hovering the left
 * half of a star picks N.5; hovering the right half picks N+1.
 *
 * Read-only mode displays a static rating without pointer events.
 */
@Component({
  selector: 'app-ax-rating',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxRatingComponent),
      multi: true,
    },
  ],
  template: `
    <span
      class="ax-rating"
      [class.ax-rating-sm]="size === 'sm'"
      [class.ax-rating-lg]="size === 'lg'"
      [class.ax-rating-xl]="size === 'xl'"
      [class.ax-rating-readonly]="readonly || disabled"
      [class.ax-rating-interactive]="!readonly && !disabled"
      role="slider"
      [attr.aria-valuemin]="0"
      [attr.aria-valuemax]="max"
      [attr.aria-valuenow]="value"
      [attr.aria-label]="ariaLabel"
    >
      <button
        *ngFor="let i of stars; trackBy: trackByIndex"
        type="button"
        class="ax-rating-star"
        [class.ax-rating-star-filled]="(displayValue) >= i + 1"
        [disabled]="readonly || disabled"
        (mouseenter)="onHover(i + 1)"
        (mouseleave)="onHoverLeave()"
        (click)="onPick($event, i)"
        (keydown)="onKey($event, i)"
        [attr.aria-label]="(i + 1) + ' ' + (i === 0 ? 'star' : 'stars')"
      >
        <svg viewBox="0 0 20 20" [attr.aria-hidden]="true">
          <ng-container *ngIf="starState(i) === 'full'">
            <path d="M10 1.5l2.584 5.237 5.78.84-4.182 4.076.987 5.757L10 14.718l-5.169 2.692.987-5.757L1.636 7.577l5.78-.84z"/>
          </ng-container>
          <ng-container *ngIf="starState(i) === 'half'">
            <defs>
              <linearGradient [attr.id]="'ax-half-' + uid + '-' + i">
                <stop offset="50%" stop-color="currentColor"/>
                <stop offset="50%" stop-color="var(--ax-color-border-strong)"/>
              </linearGradient>
            </defs>
            <path [attr.fill]="'url(#ax-half-' + uid + '-' + i + ')'"
                  d="M10 1.5l2.584 5.237 5.78.84-4.182 4.076.987 5.757L10 14.718l-5.169 2.692.987-5.757L1.636 7.577l5.78-.84z"/>
          </ng-container>
          <ng-container *ngIf="starState(i) === 'empty'">
            <path fill="var(--ax-color-border-strong)"
                  d="M10 1.5l2.584 5.237 5.78.84-4.182 4.076.987 5.757L10 14.718l-5.169 2.692.987-5.757L1.636 7.577l5.78-.84z"/>
          </ng-container>
        </svg>
      </button>

      <span *ngIf="showValue" class="ax-rating-value">{{ value.toFixed(allowHalf ? 1 : 0) }}</span>
    </span>
  `,
})
export class AxRatingComponent implements ControlValueAccessor {
  @Input() value = 0;
  @Input() max = 5;
  @Input() allowHalf = true;
  @Input() readonly = false;
  @Input() disabled = false;
  @Input() size: 'sm' | 'md' | 'lg' | 'xl' = 'md';
  @Input() showValue = false;
  @Input() ariaLabel = 'Rating';

  @Output() valueChange = new EventEmitter<number>();

  hoverValue: number | null = null;
  readonly uid = Math.floor(Math.random() * 1e6).toString(36);

  private readonly cdr = inject(ChangeDetectorRef);
  private onChange: (v: number) => void = () => {};
  private onTouched: () => void = () => {};

  get stars(): number[] {
    return Array.from({ length: this.max }, (_, i) => i);
  }

  /** The value being rendered — hover value takes precedence while user is hovering. */
  get displayValue(): number {
    return this.hoverValue ?? this.value;
  }

  /** Which state to render for the ith star: 'full' | 'half' | 'empty'. */
  starState(i: number): 'full' | 'half' | 'empty' {
    const v = this.displayValue;
    if (v >= i + 1) return 'full';
    if (this.allowHalf && v >= i + 0.5) return 'half';
    return 'empty';
  }

  onHover(starIndex: number): void {
    if (this.readonly || this.disabled) return;
    this.hoverValue = starIndex;
    this.cdr.markForCheck();
  }

  onHoverLeave(): void {
    this.hoverValue = null;
    this.cdr.markForCheck();
  }

  onPick(event: MouseEvent, starIndex: number): void {
    if (this.readonly || this.disabled) return;
    event.preventDefault();
    let picked = starIndex + 1;
    if (this.allowHalf) {
      // Determine which half was clicked
      const target = event.currentTarget as HTMLElement;
      const rect = target.getBoundingClientRect();
      const xFrac = (event.clientX - rect.left) / rect.width;
      if (xFrac < 0.5) picked = starIndex + 0.5;
    }
    this.setValue(picked);
  }

  onKey(event: KeyboardEvent, starIndex: number): void {
    if (this.readonly || this.disabled) return;
    const step = this.allowHalf ? 0.5 : 1;
    let next = this.value;
    switch (event.key) {
      case 'ArrowRight':
      case 'ArrowUp':
        next = Math.min(this.max, this.value + step);
        break;
      case 'ArrowLeft':
      case 'ArrowDown':
        next = Math.max(0, this.value - step);
        break;
      case 'Home': next = 0; break;
      case 'End':  next = this.max; break;
      case 'Enter':
      case ' ':
        next = starIndex + 1;
        break;
      default:
        return;
    }
    event.preventDefault();
    this.setValue(next);
  }

  private setValue(v: number): void {
    this.value = v;
    this.valueChange.emit(v);
    this.onChange(v);
    this.onTouched();
    this.cdr.markForCheck();
  }

  trackByIndex = (i: number) => i;

  // ControlValueAccessor
  writeValue(value: number | null): void {
    this.value = typeof value === 'number' ? value : 0;
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (v: number) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(disabled: boolean): void { this.disabled = disabled; this.cdr.markForCheck(); }
}
