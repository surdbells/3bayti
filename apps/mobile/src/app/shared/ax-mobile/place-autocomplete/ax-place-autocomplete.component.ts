/**
 * AxPlaceAutocompleteComponent — address autocomplete via Google Places (New).
 *
 * Drop-in replacement for <ax-text-field> when an address is being captured.
 * Behavior:
 *   - User types -> debounced Places Autocomplete call
 *   - Suggestions render in a dropdown below the input
 *   - User taps a suggestion -> Place Details fetched -> (placeSelected) emits
 *     a structured PlaceDetails object with parsed street/city/area/country
 *   - Falls back gracefully to plain text input if the API key is missing
 *     or the network call fails. (placeSelected) just doesn't fire in that
 *     case; the consumer can save the typed text as-is.
 *
 * The visible <input> remains a normal text field — ngModel two-way binds the
 * RAW text. Consumers should NOT rely on ngModel alone if they want structured
 * data; they need (placeSelected) too.
 *
 * Usage:
 *
 *   <ax-place-autocomplete
 *     [label]="'Street address' | translate"
 *     [(ngModel)]="update.street"
 *     name="street"
 *     (placeSelected)="onPlaceSelected($event)">
 *   </ax-place-autocomplete>
 *
 *   onPlaceSelected(place: PlaceDetails) {
 *     this.update.street = place.street ?? this.update.street;
 *     // Optionally try to match place.city / place.area against your
 *     // server-side City/Area lists.
 *   }
 *
 * Visual design intentionally mirrors <ax-text-field> so swapping in/out
 * is invisible to the user. The dropdown sits below the input; max-height
 * + scroll for long results lists. Dark-mode aware via tokens.
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
  ViewChild,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { ControlValueAccessor, NG_VALUE_ACCESSOR } from '@angular/forms';

import { AxIconComponent } from '../icon';
import { PlacesService, PlaceSuggestion, PlaceDetails } from '../../../service/places.service';

let instanceCounter = 0;

@Component({
  selector: 'ax-place-autocomplete',
  standalone: true,
  imports: [CommonModule, AxIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-place-autocomplete.component.html',
  styleUrl: './ax-place-autocomplete.component.scss',
  providers: [
    {
      provide: NG_VALUE_ACCESSOR,
      useExisting: forwardRef(() => AxPlaceAutocompleteComponent),
      multi: true,
    },
  ],
})
export class AxPlaceAutocompleteComponent implements ControlValueAccessor, OnDestroy {
  @Input() label = '';
  @Input() placeholder = '';
  @Input() name = '';
  @Input() required = false;
  @Input() autocomplete = 'street-address';

  /** Optional helper text below the input. */
  @Input() hint = '';
  /** Optional error text below the input. Takes precedence over hint. */
  @Input() error = '';

  /**
   * Fired when the user taps a suggestion AND the Place Details fetch
   * succeeds. Consumers use this for structured data (street, city, area,
   * lat/lng). Not fired for typed text — only for selection events.
   */
  @Output() placeSelected = new EventEmitter<PlaceDetails>();

  /** Stable id for label-input pairing. */
  fieldId = `ax-place-autocomplete-${++instanceCounter}`;

  /** Internal raw text. ngModel mirrors this. */
  value = '';

  /** Current suggestions list — drives the dropdown render. */
  suggestions: PlaceSuggestion[] = [];

  /** True while an autocomplete request is in flight (for the spinner). */
  loading = false;

  /** True once the input has focus AND we have suggestions to show. */
  open = false;

  /** Whether the API is configured. If false, we render a plain field. */
  isAvailable = false;

  /** Programmatic ref to the <input> for blur/focus management. */
  @ViewChild('inputEl') inputEl?: ElementRef<HTMLInputElement>;

  /** Debounce timer id for autocomplete requests. */
  private debounceHandle: ReturnType<typeof setTimeout> | null = null;
  /** Most recent input we sent to the API. Used to ignore stale responses. */
  private lastQuery = '';
  /** True when we're currently fetching details (after a tap). Prevents
      an in-flight blur from closing the dropdown before details land. */
  private fetchingDetails = false;

  /** ControlValueAccessor callbacks. */
  private onChange: (value: string) => void = () => {};
  private onTouched: () => void = () => {};
  disabled = false;

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

  /* ----- ControlValueAccessor ----- */

  writeValue(value: string | null): void {
    this.value = value ?? '';
    this.cdr.markForCheck();
  }
  registerOnChange(fn: (value: string) => void): void {
    this.onChange = fn;
  }
  registerOnTouched(fn: () => void): void {
    this.onTouched = fn;
  }
  setDisabledState(isDisabled: boolean): void {
    this.disabled = isDisabled;
    this.cdr.markForCheck();
  }

  /* ----- Input handlers ----- */

  onInput(event: Event): void {
    const text = (event.target as HTMLInputElement).value;
    this.value = text;
    this.onChange(text);

    if (!this.isAvailable) {
      /* No autocomplete — just pass through. */
      return;
    }

    /* Debounce: 250ms is a balance between responsiveness and quota.
       Each keystroke triggers a billable call (within session limits),
       so we don't want to fire on every character. */
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
    /* Don't close the dropdown if a tap on a suggestion is in-flight —
       the click handler will close it after the details fetch resolves.
       Without this, the blur fires before the click and the dropdown
       closes before the user's tap can register. */
    if (this.fetchingDetails) {
      return;
    }
    /* Small delay to let mousedown/touchstart on a suggestion complete
       before we hide it. The 150ms is empirically reliable on mobile
       webview without feeling laggy. */
    setTimeout(() => {
      this.open = false;
      this.cdr.markForCheck();
    }, 150);
    this.onTouched();
  }

  /* ----- Suggestion list ----- */

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
    /* Drop the response if a newer query has been issued. Without this,
       fast typing can let an older query's response paint over a newer
       one if the older one lands later. */
    if (this.lastQuery !== input) {
      return;
    }
    this.loading = false;
    this.suggestions = results;
    this.open = results.length > 0;
    this.cdr.markForCheck();
  }

  /* User tapped a suggestion. Fetch full details, emit, close dropdown.
     Updates the visible input text to the suggestion's main line for
     immediate feedback. */
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
      /* If Google parsed a street, prefer it over the user's free-form
         text. Otherwise keep what we have. */
      if (details.street) {
        this.value = details.street;
        this.onChange(details.street);
      }
      this.placeSelected.emit(details);
    }
    this.cdr.markForCheck();
  }

  /** Click outside to close. */
  @HostListener('document:click', ['$event'])
  onDocumentClick(event: MouseEvent): void {
    if (!this.host.nativeElement.contains(event.target)) {
      this.open = false;
      this.cdr.markForCheck();
    }
  }
}
