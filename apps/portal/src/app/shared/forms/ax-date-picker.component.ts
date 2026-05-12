import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  EventEmitter,
  Input,
  OnDestroy,
  Output,
  ViewChild,
  ViewContainerRef,
  forwardRef,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule, ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';
import {
  ConnectedPosition,
  Overlay,
  OverlayConfig,
  OverlayRef,
  OverlayModule,
} from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';

export type AxDatePickerMode = 'single' | 'range';
export type AxDateRange = { start: Date | null; end: Date | null };
type AxDatePickerValue = Date | AxDateRange | null;

type ViewMode = 'days' | 'months' | 'years';

/**
 * Unified date / date-range picker.
 *
 * Single:
 *   <app-ax-date-picker [(ngModel)]="date" placeholder="Pick a date">
 *   </app-ax-date-picker>
 *
 * Range:
 *   <app-ax-date-picker
 *     mode="range"
 *     [(ngModel)]="range"
 *     placeholder="Select date range">
 *   </app-ax-date-picker>
 *
 * Value:
 *   - mode="single" → Date | null
 *   - mode="range"  → { start: Date | null, end: Date | null } | null
 */
@Component({
  selector: 'app-ax-date-picker',
  standalone: true,
  imports: [CommonModule, FormsModule, OverlayModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxDatePickerComponent),
      multi: true,
    },
  ],
  template: `
    <button
      #trigger
      type="button"
      class="ax-select-trigger"
      [class.ax-select-trigger-open]="open"
      [class.ax-select-trigger-disabled]="disabled"
      [class.ax-select-trigger-invalid]="invalid"
      [disabled]="disabled"
      [attr.aria-label]="ariaLabel || placeholder"
      [attr.aria-expanded]="open"
      [attr.aria-haspopup]="'dialog'"
      (click)="toggle()"
      (keydown)="onTriggerKey($event)"
    >
      <span class="material-symbols-outlined ax-text-tertiary ax-mr-1" style="font-size: 1.125rem;" aria-hidden="true">calendar_today</span>
      <span class="ax-select-trigger-content">
        <ng-container *ngIf="displayLabel(); else placeholderTpl">
          <span>{{ displayLabel() }}</span>
        </ng-container>
        <ng-template #placeholderTpl>
          <span class="ax-select-trigger-placeholder">{{ placeholder }}</span>
        </ng-template>
      </span>
      <button
        *ngIf="hasValue() && allowClear && !disabled"
        type="button"
        class="ax-select-trigger-clear"
        [attr.aria-label]="'Clear date'"
        (click)="$event.stopPropagation(); clear()"
      >
        ×
      </button>
      <span class="ax-select-trigger-caret material-symbols-outlined" aria-hidden="true">expand_more</span>
    </button>

    <ng-template #panelTpl>
      <div class="ax-select-panel" style="min-width: auto; max-height: none;" role="dialog" aria-label="Choose a date">
        <div *ngIf="mode === 'range'" class="ax-calendar-range">
          <div class="ax-calendar-range-months">
            <div class="ax-calendar">
              <div class="ax-calendar-header">
                <button type="button" class="ax-calendar-nav" (click)="prevMonth()" aria-label="Previous month">
                  <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
                </button>
                <div class="ax-calendar-title">
                  <button type="button" class="ax-calendar-title-btn" (click)="setView('months')">{{ monthName(viewMonth) }}</button>
                  <button type="button" class="ax-calendar-title-btn" (click)="setView('years')">{{ viewYear }}</button>
                </div>
                <span style="width: 1.75rem;"></span>
              </div>
              <ng-container *ngTemplateOutlet="gridTpl; context: { month: viewMonth, year: viewYear }"></ng-container>
            </div>
            <div class="ax-calendar">
              <div class="ax-calendar-header">
                <span style="width: 1.75rem;"></span>
                <div class="ax-calendar-title">
                  <span class="ax-calendar-title-btn">{{ monthName(nextViewMonth) }}</span>
                  <span class="ax-calendar-title-btn">{{ nextViewYear }}</span>
                </div>
                <button type="button" class="ax-calendar-nav" (click)="nextMonth()" aria-label="Next month">
                  <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
                </button>
              </div>
              <ng-container *ngTemplateOutlet="gridTpl; context: { month: nextViewMonth, year: nextViewYear }"></ng-container>
            </div>
          </div>
          <div class="ax-calendar-footer ax-px-3 ax-pb-3">
            <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="clear(); close()">Clear</button>
            <div class="ax-flex ax-gap-2">
              <button type="button" class="ax-btn ax-btn-ghost ax-btn-sm" (click)="close()">Cancel</button>
              <button type="button" class="ax-btn ax-btn-primary ax-btn-sm" [disabled]="!rangeStart || !rangeEnd" (click)="applyRange()">Apply</button>
            </div>
          </div>
        </div>

        <div *ngIf="mode === 'single'" class="ax-calendar">
          <div class="ax-calendar-header">
            <button type="button" class="ax-calendar-nav" (click)="prevMonth()" aria-label="Previous month">
              <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
            </button>
            <div class="ax-calendar-title">
              <button type="button" class="ax-calendar-title-btn" (click)="setView('months')">{{ monthName(viewMonth) }}</button>
              <button type="button" class="ax-calendar-title-btn" (click)="setView('years')">{{ viewYear }}</button>
            </div>
            <button type="button" class="ax-calendar-nav" (click)="nextMonth()" aria-label="Next month">
              <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
            </button>
          </div>
          <ng-container *ngIf="viewMode === 'days'">
            <ng-container *ngTemplateOutlet="gridTpl; context: { month: viewMonth, year: viewYear }"></ng-container>
          </ng-container>
          <div *ngIf="viewMode === 'months'" class="ax-calendar-picker">
            <button *ngFor="let m of monthList; let i = index" type="button"
                    class="ax-calendar-picker-item"
                    [class.ax-calendar-picker-item-selected]="i === viewMonth"
                    (click)="pickMonth(i)">
              {{ m }}
            </button>
          </div>
          <div *ngIf="viewMode === 'years'" class="ax-calendar-picker">
            <button *ngFor="let y of yearList" type="button"
                    class="ax-calendar-picker-item"
                    [class.ax-calendar-picker-item-selected]="y === viewYear"
                    (click)="pickYear(y)">
              {{ y }}
            </button>
          </div>
        </div>
      </div>
    </ng-template>

    <ng-template #gridTpl let-month="month" let-year="year">
      <div class="ax-calendar-weekdays">
        <div *ngFor="let d of weekdays" class="ax-calendar-weekday">{{ d }}</div>
      </div>
      <div class="ax-calendar-grid">
        <button *ngFor="let day of buildMonth(month, year); trackBy: trackByDate"
                type="button"
                class="ax-calendar-day"
                [class.ax-calendar-day-outside]="day.outside"
                [class.ax-calendar-day-today]="day.today"
                [class.ax-calendar-day-selected]="isDaySelected(day.date)"
                [class.ax-calendar-day-range]="isInRange(day.date)"
                [class.ax-calendar-day-range-start]="isRangeStart(day.date)"
                [class.ax-calendar-day-range-end]="isRangeEnd(day.date)"
                [disabled]="isDisabled(day.date)"
                (click)="pickDay(day.date)"
                [attr.aria-label]="day.date.toDateString()"
                [attr.aria-pressed]="isDaySelected(day.date)">
          {{ day.date.getDate() }}
        </button>
      </div>
    </ng-template>
  `,
})
export class AxDatePickerComponent implements ControlValueAccessor, OnDestroy {
  @Input() mode: AxDatePickerMode = 'single';
  @Input() placeholder = 'Select date';
  @Input() allowClear = true;
  @Input() ariaLabel?: string;
  @Input() invalid = false;
  @Input() min?: Date;
  @Input() max?: Date;
  /** First day of week: 0 = Sun, 1 = Mon */
  @Input() firstDayOfWeek: 0 | 1 = 1;
  /** Format single date (ISO yyyy-MM-dd by default) */
  @Input() displayFormat: (d: Date) => string = this.defaultFormat;

  @Output() openChange = new EventEmitter<boolean>();
  @Output() valueChange = new EventEmitter<AxDatePickerValue>();

  @ViewChild('trigger', { static: true }) trigger!: ElementRef<HTMLButtonElement>;
  @ViewChild('panelTpl', { static: true }) panelTpl!: any;

  value: AxDatePickerValue = null;
  open = false;
  disabled = false;

  // View state
  viewYear = new Date().getFullYear();
  viewMonth = new Date().getMonth();
  viewMode: ViewMode = 'days';

  // Range selection in-progress
  rangeStart: Date | null = null;
  rangeEnd: Date | null = null;

  readonly monthList = [
    'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun',
    'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec',
  ];

  private overlayRef: OverlayRef | null = null;
  private onChange: (value: AxDatePickerValue) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(
    private overlay: Overlay,
    private cdr: ChangeDetectorRef,
    private viewContainerRef: ViewContainerRef,
  ) {}

  // ---- ControlValueAccessor ----
  writeValue(value: AxDatePickerValue): void {
    this.value = this.normalise(value);
    if (this.mode === 'range' && this.value && typeof this.value === 'object' && 'start' in this.value) {
      this.rangeStart = this.value.start;
      this.rangeEnd = this.value.end;
    } else if (this.mode === 'single' && this.value instanceof Date) {
      this.viewMonth = this.value.getMonth();
      this.viewYear = this.value.getFullYear();
    }
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (value: AxDatePickerValue) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  // ---- Display helpers ----
  private defaultFormat(d: Date): string {
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${d.getFullYear()}-${mm}-${dd}`;
  }

  displayLabel(): string | null {
    if (this.mode === 'single' && this.value instanceof Date) {
      return this.displayFormat(this.value);
    }
    if (this.mode === 'range' && this.value && typeof this.value === 'object' && 'start' in this.value) {
      const { start, end } = this.value;
      if (start && end) {
        return `${this.displayFormat(start)}  →  ${this.displayFormat(end)}`;
      }
      if (start) return `${this.displayFormat(start)}  →  ...`;
    }
    return null;
  }

  hasValue(): boolean {
    if (this.mode === 'single') return this.value instanceof Date;
    if (this.mode === 'range' && this.value && typeof this.value === 'object' && 'start' in this.value) {
      return this.value.start != null || this.value.end != null;
    }
    return false;
  }

  private normalise(v: AxDatePickerValue): AxDatePickerValue {
    if (!v) return null;
    if (this.mode === 'single') {
      if (v instanceof Date) return v;
      if (typeof v === 'string') return new Date(v);
      return null;
    }
    // range
    if (typeof v === 'object' && 'start' in v) {
      return {
        start: v.start instanceof Date ? v.start : (v.start ? new Date(v.start as any) : null),
        end:   v.end instanceof Date ? v.end : (v.end ? new Date(v.end as any) : null),
      };
    }
    return null;
  }

  // ---- Overlay ----
  toggle(): void {
    if (this.disabled) return;
    this.open ? this.close() : this.openPanel();
  }

  openPanel(): void {
    if (this.open) return;
    const positions: ConnectedPosition[] = [
      { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 4 },
      { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -4 },
    ];
    const positionStrategy = this.overlay
      .position()
      .flexibleConnectedTo(this.trigger)
      .withPositions(positions)
      .withFlexibleDimensions(false);

    const config = new OverlayConfig({
      positionStrategy,
      scrollStrategy: this.overlay.scrollStrategies.reposition(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
    });

    this.overlayRef = this.overlay.create(config);
    const portal = new TemplatePortal(this.panelTpl, this.viewContainerRef);
    this.overlayRef.attach(portal);
    this.overlayRef.backdropClick().subscribe(() => this.close());
    this.overlayRef.keydownEvents().subscribe(event => {
      if (event.key === 'Escape') { event.preventDefault(); this.close(); }
    });

    // Align view to existing value
    if (this.mode === 'single' && this.value instanceof Date) {
      this.viewMonth = this.value.getMonth();
      this.viewYear = this.value.getFullYear();
    } else if (this.mode === 'range' && this.value && typeof this.value === 'object' && 'start' in this.value) {
      const anchor = this.value.start ?? this.value.end ?? new Date();
      this.viewMonth = anchor.getMonth();
      this.viewYear = anchor.getFullYear();
    }

    this.open = true;
    this.viewMode = 'days';
    this.openChange.emit(true);
    this.cdr.markForCheck();
  }

  close(): void {
    if (!this.open) return;
    this.overlayRef?.detach();
    this.overlayRef?.dispose();
    this.overlayRef = null;
    this.open = false;
    this.onTouched();
    this.openChange.emit(false);
    this.trigger.nativeElement.focus();
    this.cdr.markForCheck();
  }

  // ---- View navigation ----
  setView(mode: ViewMode): void {
    this.viewMode = mode;
    this.cdr.markForCheck();
  }

  prevMonth(): void {
    if (this.viewMonth === 0) { this.viewMonth = 11; this.viewYear--; }
    else { this.viewMonth--; }
    this.cdr.markForCheck();
  }

  nextMonth(): void {
    if (this.viewMonth === 11) { this.viewMonth = 0; this.viewYear++; }
    else { this.viewMonth++; }
    this.cdr.markForCheck();
  }

  pickMonth(m: number): void {
    this.viewMonth = m;
    this.viewMode = 'days';
    this.cdr.markForCheck();
  }

  pickYear(y: number): void {
    this.viewYear = y;
    this.viewMode = 'months';
    this.cdr.markForCheck();
  }

  get yearList(): number[] {
    const start = Math.floor(this.viewYear / 12) * 12;
    return Array.from({ length: 12 }, (_, i) => start + i);
  }

  get nextViewMonth(): number {
    return this.viewMonth === 11 ? 0 : this.viewMonth + 1;
  }
  get nextViewYear(): number {
    return this.viewMonth === 11 ? this.viewYear + 1 : this.viewYear;
  }

  monthName(m: number): string {
    return this.monthList[m];
  }

  get weekdays(): string[] {
    const base = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    if (this.firstDayOfWeek === 1) {
      return [...base.slice(1), base[0]];
    }
    return base;
  }

  // ---- Month building ----
  buildMonth(month: number, year: number): Array<{ date: Date; outside: boolean; today: boolean }> {
    const first = new Date(year, month, 1);
    const daysInMonth = new Date(year, month + 1, 0).getDate();
    const firstDayOfWeek = this.firstDayOfWeek;
    let offset = first.getDay() - firstDayOfWeek;
    if (offset < 0) offset += 7;

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const cells: Array<{ date: Date; outside: boolean; today: boolean }> = [];
    // Previous month tail
    for (let i = offset; i > 0; i--) {
      const d = new Date(year, month, 1 - i);
      d.setHours(0, 0, 0, 0);
      cells.push({ date: d, outside: true, today: d.getTime() === today.getTime() });
    }
    // Current month
    for (let day = 1; day <= daysInMonth; day++) {
      const d = new Date(year, month, day);
      d.setHours(0, 0, 0, 0);
      cells.push({ date: d, outside: false, today: d.getTime() === today.getTime() });
    }
    // Next month head — pad to 6 rows of 7
    while (cells.length < 42) {
      const last = cells[cells.length - 1].date;
      const d = new Date(last);
      d.setDate(last.getDate() + 1);
      d.setHours(0, 0, 0, 0);
      cells.push({ date: d, outside: d.getMonth() !== month, today: d.getTime() === today.getTime() });
    }
    return cells;
  }

  trackByDate = (_: number, cell: { date: Date }) => cell.date.getTime();

  // ---- Selection ----
  isDisabled(d: Date): boolean {
    const day = new Date(d); day.setHours(0, 0, 0, 0);
    if (this.min && day < this.stripTime(this.min)) return true;
    if (this.max && day > this.stripTime(this.max)) return true;
    return false;
  }

  private stripTime(d: Date): Date {
    const x = new Date(d); x.setHours(0, 0, 0, 0); return x;
  }
  private sameDay(a: Date | null, b: Date | null): boolean {
    return !!a && !!b && this.stripTime(a).getTime() === this.stripTime(b).getTime();
  }

  isDaySelected(d: Date): boolean {
    if (this.mode === 'single' && this.value instanceof Date) {
      return this.sameDay(this.value, d);
    }
    return false;
  }

  isRangeStart(d: Date): boolean {
    return this.sameDay(this.rangeStart, d);
  }
  isRangeEnd(d: Date): boolean {
    return this.sameDay(this.rangeEnd, d);
  }
  isInRange(d: Date): boolean {
    if (!this.rangeStart || !this.rangeEnd) return false;
    const t = this.stripTime(d).getTime();
    return t > this.stripTime(this.rangeStart).getTime() && t < this.stripTime(this.rangeEnd).getTime();
  }

  pickDay(d: Date): void {
    if (this.isDisabled(d)) return;
    if (this.mode === 'single') {
      this.value = d;
      this.onChange(this.value);
      this.valueChange.emit(this.value);
      this.close();
    } else {
      // Range logic: first click sets start, second sets end (auto-swap if before start)
      if (!this.rangeStart || (this.rangeStart && this.rangeEnd)) {
        this.rangeStart = d;
        this.rangeEnd = null;
      } else if (this.stripTime(d) < this.stripTime(this.rangeStart)) {
        // clicked before start → swap
        this.rangeEnd = this.rangeStart;
        this.rangeStart = d;
      } else {
        this.rangeEnd = d;
      }
      this.cdr.markForCheck();
    }
  }

  applyRange(): void {
    if (!this.rangeStart || !this.rangeEnd) return;
    const v: AxDateRange = { start: this.rangeStart, end: this.rangeEnd };
    this.value = v;
    this.onChange(v);
    this.valueChange.emit(v);
    this.close();
  }

  clear(): void {
    this.value = null;
    this.rangeStart = null;
    this.rangeEnd = null;
    this.onChange(null);
    this.valueChange.emit(null);
    this.cdr.markForCheck();
  }

  onTriggerKey(event: KeyboardEvent): void {
    if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
      event.preventDefault();
      if (!this.open) this.openPanel();
    } else if (event.key === 'Escape' && this.open) {
      event.preventDefault();
      this.close();
    }
  }

  ngOnDestroy(): void {
    this.overlayRef?.dispose();
  }
}
