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

export interface AxMultiselectOption {
  id: string | number;
  label: string;
  disabled?: boolean;
  /** Optional arbitrary data the parent can read back */
  data?: any;
}

/**
 * Multi-select dropdown with built-in search, select-all, and keyboard
 * navigation. Drop-in replacement for ng-multiselect-dropdown across the
 * app. Implements ControlValueAccessor so it works with both template-
 * driven and reactive forms.
 *
 * Usage:
 *   <app-ax-multiselect
 *     [options]="myOptions"
 *     [(ngModel)]="selected"
 *     placeholder="Choose categories"
 *     [maxSelections]="5">
 *   </app-ax-multiselect>
 *
 * Value emitted is an array of ids matching `AxMultiselectOption.id`.
 */
@Component({
  selector: 'app-ax-multiselect',
  standalone: true,
  imports: [CommonModule, FormsModule, OverlayModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxMultiselectComponent),
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
      [attr.aria-haspopup]="'listbox'"
      [attr.aria-expanded]="open"
      [attr.aria-label]="ariaLabel || placeholder"
      (click)="toggle()"
      (keydown)="onTriggerKey($event)"
    >
      <span class="ax-select-trigger-content">
        <ng-container *ngIf="selectedOptions.length; else placeholderTpl">
          <ng-container *ngIf="chipsMode; else countMode">
            <span *ngFor="let opt of selectedOptions.slice(0, chipLimit)" class="ax-select-chip">
              <span class="ax-select-chip-label">{{ opt.label }}</span>
              <button
                type="button"
                class="ax-select-chip-remove"
                [attr.aria-label]="'Remove ' + opt.label"
                (click)="$event.stopPropagation(); remove(opt)"
              >
                ×
              </button>
            </span>
            <span *ngIf="selectedOptions.length > chipLimit" class="ax-text-2xs ax-text-tertiary ax-ml-1">
              +{{ selectedOptions.length - chipLimit }} more
            </span>
          </ng-container>
          <ng-template #countMode>
            <span>{{ selectedOptions.length }} selected</span>
          </ng-template>
        </ng-container>
        <ng-template #placeholderTpl>
          <span class="ax-select-trigger-placeholder">{{ placeholder }}</span>
        </ng-template>
      </span>

      <button
        *ngIf="selectedOptions.length && allowClear && !disabled"
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
      <div
        class="ax-select-panel"
        [style.width.px]="panelWidth"
        role="listbox"
        [attr.aria-multiselectable]="'true'"
      >
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
              (keydown)="onSearchKey($event)"
              aria-label="Search options"
            />
          </div>
        </div>

        <div class="ax-select-panel-actions" *ngIf="showSelectAll || selectedOptions.length">
          <span class="ax-select-panel-actions-left">
            <ng-container *ngIf="!maxSelections">{{ selectedOptions.length }} selected</ng-container>
            <ng-container *ngIf="maxSelections">{{ selectedOptions.length }} / {{ maxSelections }} selected</ng-container>
          </span>
          <button *ngIf="showSelectAll && !maxSelections" type="button" (click)="selectAll()">
            {{ allFilteredSelected() ? 'Clear all' : 'Select all' }}
          </button>
        </div>

        <div class="ax-select-panel-options" role="presentation">
          <ng-container *ngIf="filtered.length; else emptyTpl">
            <div
              *ngFor="let opt of filtered; let i = index; trackBy: trackById"
              class="ax-select-panel-option"
              [class.ax-select-panel-option-selected]="isSelected(opt)"
              [class.ax-select-panel-option-active]="i === activeIndex"
              [class.ax-select-panel-option-disabled]="opt.disabled || isMaxed(opt)"
              role="option"
              [attr.aria-selected]="isSelected(opt)"
              (click)="toggleOption(opt)"
              (mouseenter)="activeIndex = i"
            >
              <span class="ax-check ax-shrink-0" style="pointer-events: none;">
                <input type="checkbox" [checked]="isSelected(opt)" tabindex="-1" />
                <span class="ax-check-box"></span>
              </span>
              <span class="ax-select-panel-option-label">{{ opt.label }}</span>
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
export class AxMultiselectComponent implements ControlValueAccessor, OnDestroy {
  @Input() options: AxMultiselectOption[] = [];
  @Input() placeholder = 'Select...';
  @Input() searchable = true;
  @Input() searchPlaceholder = 'Search...';
  @Input() showSelectAll = true;
  @Input() maxSelections?: number;
  @Input() allowClear = true;
  /** Show chips in trigger (default) vs. count */
  @Input() chipsMode = true;
  @Input() chipLimit = 3;
  @Input() ariaLabel?: string;
  @Input() invalid = false;

  @Output() openChange = new EventEmitter<boolean>();
  @Output() selectionChange = new EventEmitter<(string | number)[]>();

  @ViewChild('trigger', { static: true }) trigger!: ElementRef<HTMLButtonElement>;
  @ViewChild('searchInput') searchInput?: ElementRef<HTMLInputElement>;
  @ViewChild('panelTpl', { static: true }) panelTpl!: any;

  value: (string | number)[] = [];
  open = false;
  disabled = false;
  searchTerm = '';
  activeIndex = -1;
  panelWidth = 0;

  private overlayRef: OverlayRef | null = null;
  private onChange: (value: (string | number)[]) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(
    private overlay: Overlay,
    private cdr: ChangeDetectorRef,
    private viewContainerRef: ViewContainerRef,
  ) {}

  // ---- ControlValueAccessor ----
  writeValue(value: (string | number)[] | null): void {
    this.value = Array.isArray(value) ? [...value] : [];
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (value: (string | number)[]) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  // ---- Computed ----
  get selectedOptions(): AxMultiselectOption[] {
    return this.options.filter(o => this.value.includes(o.id));
  }

  get filtered(): AxMultiselectOption[] {
    const t = this.searchTerm.trim().toLowerCase();
    if (!t) return this.options;
    return this.options.filter(o => o.label.toLowerCase().includes(t));
  }

  isSelected(opt: AxMultiselectOption): boolean {
    return this.value.includes(opt.id);
  }

  isMaxed(opt: AxMultiselectOption): boolean {
    if (!this.maxSelections) return false;
    if (this.isSelected(opt)) return false;
    return this.value.length >= this.maxSelections;
  }

  allFilteredSelected(): boolean {
    if (!this.filtered.length) return false;
    return this.filtered.every(o => this.isSelected(o) || o.disabled);
  }

  trackById = (_: number, o: AxMultiselectOption) => o.id;

  // ---- Open / close ----
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
      .withFlexibleDimensions(false)
      .withPush(false);

    const config = new OverlayConfig({
      positionStrategy,
      scrollStrategy: this.overlay.scrollStrategies.reposition(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
      panelClass: 'ax-overlay-pane',
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

    // Focus search after render
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

  // ---- Selection ----
  toggleOption(opt: AxMultiselectOption): void {
    if (opt.disabled) return;
    if (this.isMaxed(opt)) return;
    if (this.isSelected(opt)) {
      this.value = this.value.filter(id => id !== opt.id);
    } else {
      this.value = [...this.value, opt.id];
    }
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.cdr.markForCheck();
  }

  remove(opt: AxMultiselectOption): void {
    this.value = this.value.filter(id => id !== opt.id);
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.cdr.markForCheck();
  }

  clear(): void {
    this.value = [];
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.cdr.markForCheck();
  }

  selectAll(): void {
    if (this.allFilteredSelected()) {
      const filteredIds = this.filtered.map(o => o.id);
      this.value = this.value.filter(id => !filteredIds.includes(id));
    } else {
      const toAdd = this.filtered.filter(o => !o.disabled && !this.isSelected(o)).map(o => o.id);
      this.value = [...this.value, ...toAdd];
    }
    this.onChange(this.value);
    this.selectionChange.emit(this.value);
    this.cdr.markForCheck();
  }

  onSearch(term: string): void {
    this.searchTerm = term;
    this.activeIndex = 0;
    this.cdr.markForCheck();
  }

  // ---- Keyboard ----
  onTriggerKey(event: KeyboardEvent): void {
    if (['ArrowDown', 'Enter', ' '].includes(event.key)) {
      event.preventDefault();
      if (!this.open) this.openPanel();
    } else if (event.key === 'Escape' && this.open) {
      event.preventDefault();
      this.close();
    }
  }

  onSearchKey(event: KeyboardEvent): void {
    this.onPanelKey(event);
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
      case ' ': // Space
        if (this.activeIndex >= 0 && this.activeIndex < list.length) {
          event.preventDefault();
          this.toggleOption(list[this.activeIndex]);
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
