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

export interface AxComboboxOption {
  id: string | number;
  label: string;
  disabled?: boolean;
  data?: any;
}

/**
 * Searchable single-select dropdown. Useful for longer option lists
 * (countries, categories, cities) where a native <select> is unwieldy.
 *
 * Usage:
 *   <app-ax-combobox
 *     [options]="countries"
 *     [(ngModel)]="selectedCountry"
 *     placeholder="Select a country"
 *     [searchable]="true">
 *   </app-ax-combobox>
 *
 * Value is a single id matching AxComboboxOption.id, or null.
 */
@Component({
  selector: 'app-ax-combobox',
  standalone: true,
  imports: [CommonModule, FormsModule, OverlayModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxComboboxComponent),
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
      role="combobox"
      [attr.aria-haspopup]="'listbox'"
      [attr.aria-expanded]="open"
      [attr.aria-label]="ariaLabel || placeholder"
      (click)="toggle()"
      (keydown)="onTriggerKey($event)"
    >
      <span class="ax-select-trigger-content">
        <ng-container *ngIf="selectedOption; else placeholderTpl">
          <span>{{ selectedOption.label }}</span>
        </ng-container>
        <ng-template #placeholderTpl>
          <span class="ax-select-trigger-placeholder">{{ placeholder }}</span>
        </ng-template>
      </span>

      <button
        *ngIf="selectedOption && allowClear && !disabled"
        type="button"
        class="ax-select-trigger-clear"
        [attr.aria-label]="'Clear selection'"
        (click)="$event.stopPropagation(); clear()"
      >
        ×
      </button>
      <span class="ax-select-trigger-caret material-symbols-outlined" aria-hidden="true">expand_more</span>
    </button>

    <ng-template #panelTpl>
      <div class="ax-select-panel" [style.width.px]="panelWidth" role="listbox">
        <div *ngIf="searchable" class="ax-select-panel-header">
          <div class="ax-select-panel-search">
            <span class="material-symbols-outlined" aria-hidden="true">search</span>
            <input
              #searchInput
              type="text"
              class="ax-select-panel-search-input"
              [placeholder]="searchPlaceholder"
              [ngModel]="searchTerm"
              (ngModelChange)="onSearch($event)"
              (keydown)="onPanelKey($event)"
              aria-label="Search options"
            />
          </div>
        </div>

        <div class="ax-select-panel-options" role="presentation">
          <ng-container *ngIf="filtered.length; else emptyTpl">
            <div
              *ngFor="let opt of filtered; let i = index; trackBy: trackById"
              class="ax-select-panel-option"
              [class.ax-select-panel-option-selected]="isSelected(opt)"
              [class.ax-select-panel-option-active]="i === activeIndex"
              [class.ax-select-panel-option-disabled]="opt.disabled"
              role="option"
              [attr.aria-selected]="isSelected(opt)"
              (click)="selectOption(opt)"
              (mouseenter)="activeIndex = i"
            >
              <span class="ax-select-panel-option-label">{{ opt.label }}</span>
              <span *ngIf="isSelected(opt)" class="ax-select-panel-option-check">
                <span class="material-symbols-outlined" aria-hidden="true">check</span>
              </span>
            </div>
          </ng-container>
          <ng-template #emptyTpl>
            <div class="ax-select-panel-empty">
              {{ searchTerm ? 'No matches for "' + searchTerm + '"' : 'No options available' }}
            </div>
          </ng-template>
        </div>
      </div>
    </ng-template>
  `,
})
export class AxComboboxComponent implements ControlValueAccessor, OnDestroy {
  @Input() options: AxComboboxOption[] = [];
  @Input() placeholder = 'Select...';
  @Input() searchable = true;
  @Input() searchPlaceholder = 'Search...';
  @Input() allowClear = true;
  @Input() ariaLabel?: string;
  @Input() invalid = false;

  @Output() openChange = new EventEmitter<boolean>();
  @Output() selectionChange = new EventEmitter<string | number | null>();

  @ViewChild('trigger', { static: true }) trigger!: ElementRef<HTMLButtonElement>;
  @ViewChild('searchInput') searchInput?: ElementRef<HTMLInputElement>;
  @ViewChild('panelTpl', { static: true }) panelTpl!: any;

  value: string | number | null = null;
  open = false;
  disabled = false;
  searchTerm = '';
  activeIndex = -1;
  panelWidth = 0;

  private overlayRef: OverlayRef | null = null;
  private onChange: (value: string | number | null) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(
    private overlay: Overlay,
    private cdr: ChangeDetectorRef,
    private viewContainerRef: ViewContainerRef,
  ) {}

  writeValue(value: string | number | null): void {
    this.value = value ?? null;
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (value: string | number | null) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  get selectedOption(): AxComboboxOption | undefined {
    if (this.value == null) return undefined;
    return this.options.find(o => o.id === this.value);
  }

  get filtered(): AxComboboxOption[] {
    const t = this.searchTerm.trim().toLowerCase();
    if (!t) return this.options;
    return this.options.filter(o => o.label.toLowerCase().includes(t));
  }

  isSelected(opt: AxComboboxOption): boolean {
    return opt.id === this.value;
  }

  trackById = (_: number, o: AxComboboxOption) => o.id;

  toggle(): void {
    if (this.disabled) return;
    this.open ? this.close() : this.openPanel();
  }

  openPanel(): void {
    if (this.open) return;
    this.panelWidth = this.trigger.nativeElement.getBoundingClientRect().width;

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
    this.overlayRef.keydownEvents().subscribe(event => this.onPanelKey(event));

    this.open = true;
    this.activeIndex = this.filtered.findIndex(o => this.isSelected(o));
    if (this.activeIndex < 0) this.activeIndex = 0;
    this.openChange.emit(true);
    this.cdr.markForCheck();

    setTimeout(() => this.searchInput?.nativeElement.focus(), 0);
  }

  close(): void {
    if (!this.open) return;
    this.overlayRef?.detach();
    this.overlayRef?.dispose();
    this.overlayRef = null;
    this.open = false;
    this.searchTerm = '';
    this.activeIndex = -1;
    this.onTouched();
    this.openChange.emit(false);
    this.trigger.nativeElement.focus();
    this.cdr.markForCheck();
  }

  selectOption(opt: AxComboboxOption): void {
    if (opt.disabled) return;
    this.value = opt.id;
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.close();
  }

  clear(): void {
    this.value = null;
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.cdr.markForCheck();
  }

  onSearch(term: string): void {
    this.searchTerm = term;
    this.activeIndex = 0;
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

  onPanelKey(event: KeyboardEvent): void {
    const list = this.filtered;
    if (!list.length) {
      if (event.key === 'Escape') { event.preventDefault(); this.close(); }
      return;
    }
    switch (event.key) {
      case 'ArrowDown':
        event.preventDefault();
        this.activeIndex = (this.activeIndex + 1) % list.length;
        this.cdr.markForCheck();
        break;
      case 'ArrowUp':
        event.preventDefault();
        this.activeIndex = (this.activeIndex - 1 + list.length) % list.length;
        this.cdr.markForCheck();
        break;
      case 'Home':
        event.preventDefault();
        this.activeIndex = 0;
        this.cdr.markForCheck();
        break;
      case 'End':
        event.preventDefault();
        this.activeIndex = list.length - 1;
        this.cdr.markForCheck();
        break;
      case 'Enter':
        if (this.activeIndex >= 0 && this.activeIndex < list.length) {
          event.preventDefault();
          this.selectOption(list[this.activeIndex]);
        }
        break;
      case 'Escape':
        event.preventDefault();
        this.close();
        break;
    }
  }

  ngOnDestroy(): void {
    this.overlayRef?.dispose();
  }
}
