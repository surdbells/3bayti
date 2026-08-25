/**
 * AxPlaceAutocompleteComponent — address autocomplete via Google Places (New).
 *
 * Portal port of the mobile ax-place-autocomplete. Drop-in for a plain
 * <input class="ax-input"> when capturing an address: ngModel two-way binds the
 * RAW text, and (placeSelected) emits the structured PlaceDetails on selection.
 * Degrades to a plain text field when the API key is absent or a call fails.
 *
 *   <app-ax-place-autocomplete
 *     name="store_address" [(ngModel)]="store.store_address"
 *     placeholder="Search your store address" [preferFormatted]="true"
 *     (placeSelected)="onStorePlace($event)">
 *   </app-ax-place-autocomplete>
 *
 * `preferFormatted` = true sets the input to the FULL formatted address on
 * selection (right for a store location); the default sets it to the parsed
 * street (right for a street-address field).
 */
import {
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ElementRef,
  EventEmitter,
  forwardRef,
  HostListener,
  Input,
  OnDestroy,
  Output,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { IconComponent } from '../icon/icon.component';
import { PlacesService, PlaceSuggestion, PlaceDetails } from '../../core/places/places.service';

@Component({
  selector: 'app-ax-place-autocomplete',
  standalone: true,
  imports: [CommonModule, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  providers: [
    { provide: NG_VALUE_ACCESSOR, useExisting: forwardRef(() => AxPlaceAutocompleteComponent), multi: true },
  ],
  template: `
    <div class="axpa">
      <div class="ax-input-group">
        <app-icon name="search" aria-hidden="true" class="ax-input-group-prefix"></app-icon>
        <input
          type="text"
          class="ax-input axpa-input"
          [value]="value"
          [attr.placeholder]="placeholder"
          [attr.name]="name"
          [attr.maxlength]="maxlength"
          [attr.autocomplete]="autocomplete"
          [disabled]="disabled"
          role="combobox"
          aria-autocomplete="list"
          [attr.aria-expanded]="open"
          (input)="onInput($event)"
          (focus)="onFocus()"
          (blur)="onBlur()" />
        <span *ngIf="loading" class="ax-spinner ax-spinner-sm axpa-spinner" aria-label="Searching"></span>
      </div>

      <ul *ngIf="open && suggestions.length" class="axpa-menu" role="listbox">
        <li *ngFor="let s of suggestions"
            class="axpa-item"
            role="option"
            (mousedown)="onSelectSuggestion(s); $event.preventDefault()">
          <app-icon name="storefront" aria-hidden="true" class="axpa-item-icon"></app-icon>
          <span class="axpa-item-text">
            <span class="axpa-item-main">{{ s.mainText }}</span>
            <span class="axpa-item-sub" *ngIf="s.secondaryText">{{ s.secondaryText }}</span>
          </span>
        </li>
      </ul>
    </div>
  `,
  styles: [`
    .axpa { position: relative; }
    .axpa-input { padding-left: 2rem; }
    .axpa-spinner { position: absolute; right: 0.625rem; top: 50%; transform: translateY(-50%); }
    .axpa-menu {
      position: absolute; z-index: 40; left: 0; right: 0; top: calc(100% + 0.25rem);
      margin: 0; padding: 0.25rem; list-style: none;
      background: var(--ax-color-bg-raised, #fff);
      border: 1px solid var(--ax-color-border-subtle, #e5e2dc);
      border-radius: var(--ax-radius-md, 0.5rem);
      box-shadow: 0 8px 24px rgba(0, 0, 0, 0.12);
      max-height: 18rem; overflow-y: auto;
    }
    .axpa-item {
      display: flex; align-items: flex-start; gap: 0.5rem;
      padding: 0.5rem 0.625rem; border-radius: var(--ax-radius-sm, 0.375rem);
      cursor: pointer;
    }
    .axpa-item:hover { background: var(--ax-color-surface-hover, #f4f1ec); }
    .axpa-item-icon { color: var(--ax-color-text-tertiary, #8a8a8a); margin-top: 0.125rem; flex: none; }
    .axpa-item-text { display: flex; flex-direction: column; min-width: 0; }
    .axpa-item-main { color: var(--ax-color-text-primary, #2a2a2a); font-size: 0.875rem; }
    .axpa-item-sub { color: var(--ax-color-text-tertiary, #8a8a8a); font-size: 0.75rem; }
  `],
})
export class AxPlaceAutocompleteComponent implements ControlValueAccessor, OnDestroy {
  @Input() placeholder = '';
  @Input() name = '';
  @Input() maxlength = 255;
  @Input() autocomplete = 'off';
  /** true → set the input to the FULL formatted address on select (store location). */
  @Input() preferFormatted = false;

  @Output() placeSelected = new EventEmitter<PlaceDetails>();

  value = '';
  suggestions: PlaceSuggestion[] = [];
  loading = false;
  open = false;
  isAvailable = false;
  disabled = false;

  private debounceHandle: ReturnType<typeof setTimeout> | null = null;
  private lastQuery = '';
  private fetchingDetails = false;

  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};

  constructor(
    private places: PlacesService,
    private cdr: ChangeDetectorRef,
    private host: ElementRef,
  ) {
    this.isAvailable = this.places.isAvailable;
  }

  ngOnDestroy(): void {
    if (this.debounceHandle !== null) {
      clearTimeout(this.debounceHandle);
    }
  }

  writeValue(value: string | null): void {
    this.value = value ?? '';
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (value: string) => void): void { this.onChange = fn; }
  registerOnTouched(fn: () => void): void { this.onTouched = fn; }
  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  onInput(event: Event): void {
    const text = (event.target as HTMLInputElement).value;
    this.value = text;
    this.onChange(text);
    if (!this.isAvailable) {
      return; // plain text field
    }
    if (this.debounceHandle !== null) {
      clearTimeout(this.debounceHandle);
    }
    this.debounceHandle = setTimeout(() => this.fetchSuggestions(text), 250);
  }

  onFocus(): void {
    if (this.suggestions.length > 0) {
      this.open = true;
      this.cdr.markForCheck();
    }
  }

  onBlur(): void {
    if (this.fetchingDetails) {
      return;
    }
    setTimeout(() => {
      this.open = false;
      this.cdr.markForCheck();
    }, 150);
    this.onTouched();
  }

  private async fetchSuggestions(input: string): Promise<void> {
    this.lastQuery = input;
    if (!input || input.trim().length < 2) {
      this.suggestions = [];
      this.open = false;
      this.cdr.markForCheck();
      return;
    }
    this.loading = true;
    this.cdr.markForCheck();
    const results = await this.places.autocomplete(input);
    if (this.lastQuery !== input) {
      return; // stale response
    }
    this.loading = false;
    this.suggestions = results;
    this.open = results.length > 0;
    this.cdr.markForCheck();
  }

  async onSelectSuggestion(s: PlaceSuggestion): Promise<void> {
    this.fetchingDetails = true;
    this.value = s.mainText;
    this.onChange(s.mainText);
    this.suggestions = [];
    this.open = false;
    this.cdr.markForCheck();

    const details = await this.places.details(s.placeId);
    this.fetchingDetails = false;

    if (details) {
      const next = this.preferFormatted
        ? (details.formattedAddress || s.fullText || this.value)
        : (details.street || this.value);
      this.value = next;
      this.onChange(next);
      this.placeSelected.emit(details);
    }
    this.cdr.markForCheck();
  }

  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target)) {
      this.open = false;
      this.cdr.markForCheck();
    }
  }
}
