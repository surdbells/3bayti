import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  EventEmitter,
  HostListener,
  Input,
  Output,
  computed,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { TranslateModule, TranslateService } from '@ngx-translate/core';

/** A selectable option: a stable value plus an i18n key for its label. */
export interface SelectOption {
  value: string;
  labelKey: string;
}

/**
 * A searchable dropdown selector (combobox). Type to filter the options, click
 * to pick. Supports single- or multi-select; selected values render as
 * removable chips inside the control. Emits the full `string[]` of selected
 * values (a single-select emits an array of length 0 or 1). Closes on Escape,
 * an outside click, or (single mode) a pick. RTL-safe via logical CSS.
 */
@Component({
  selector: 'app-searchable-select',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslateModule],
  template: `
    <div class="ss" [class.ss--open]="open()" data-testid="searchable-select">
      <div class="ss__control" (click)="focusInput()">
        @for (v of _selected(); track v) {
          <span class="ss__chip">
            {{ labelForValue(v) }}
            <button
              type="button"
              class="ss__chip-x"
              (click)="remove(v, $event)"
              [attr.aria-label]="'common.close' | translate"
            >
              <svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor"
                   stroke-width="2.2" stroke-linecap="round" aria-hidden="true">
                <line x1="6" y1="6" x2="18" y2="18" /><line x1="18" y1="6" x2="6" y2="18" />
              </svg>
            </button>
          </span>
        }
        <input
          #ssInput
          type="text"
          class="ss__input"
          role="combobox"
          aria-autocomplete="list"
          [attr.aria-expanded]="open()"
          [value]="filter()"
          (input)="onFilter($event)"
          (focus)="open.set(true)"
          [attr.placeholder]="_selected().length ? '' : placeholder"
          autocomplete="off"
          spellcheck="false"
          data-testid="ss-input"
        />
        <svg class="ss__chev" viewBox="0 0 24 24" width="16" height="16" fill="none"
             stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M6 9l6 6 6-6" />
        </svg>
      </div>

      @if (open()) {
        <ul class="ss__menu" role="listbox" data-testid="ss-menu">
          @for (o of filtered(); track o.value) {
            <li>
              <button
                type="button"
                class="ss__option"
                role="option"
                [class.is-selected]="isSelected(o.value)"
                [attr.aria-selected]="isSelected(o.value)"
                (click)="toggle(o.value)"
                data-testid="ss-option"
              >
                <span>{{ o.labelKey | translate }}</span>
                @if (isSelected(o.value)) {
                  <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"
                       stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M20 6 9 17l-5-5" />
                  </svg>
                }
              </button>
            </li>
          } @empty {
            <li class="ss__empty">{{ 'search.noResults' | translate: { query: filter() } }}</li>
          }
        </ul>
      }
    </div>
  `,
  styleUrl: './searchable-select.scss',
})
export class SearchableSelectComponent {
  private readonly translate = inject(TranslateService);
  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly inputRef = viewChild<ElementRef<HTMLInputElement>>('ssInput');

  @Input() options: SelectOption[] = [];
  @Input() multiple = false;
  @Input() placeholder = '';
  @Input() set selected(v: string[] | null | undefined) {
    this._selected.set(Array.isArray(v) ? v : []);
  }
  /** Emits the full list of selected values (0..1 in single mode). */
  @Output() readonly selectedChange = new EventEmitter<string[]>();

  protected readonly _selected = signal<string[]>([]);
  protected readonly filter = signal('');
  protected readonly open = signal(false);

  protected readonly filtered = computed(() => {
    const q = this.filter().trim().toLowerCase();
    if (q === '') return this.options;
    return this.options.filter((o) => this.translate.instant(o.labelKey).toLowerCase().includes(q));
  });

  protected isSelected(v: string): boolean {
    return this._selected().includes(v);
  }

  protected labelForValue(v: string): string {
    const o = this.options.find((x) => x.value === v);
    return o ? this.translate.instant(o.labelKey) : v;
  }

  protected onFilter(event: Event): void {
    this.filter.set((event.target as HTMLInputElement).value);
    this.open.set(true);
  }

  protected focusInput(): void {
    this.open.set(true);
    this.inputRef()?.nativeElement?.focus();
  }

  protected toggle(v: string): void {
    let next: string[];
    if (this.multiple) {
      next = this.isSelected(v) ? this._selected().filter((x) => x !== v) : [...this._selected(), v];
    } else {
      next = this.isSelected(v) ? [] : [v];
    }
    this._selected.set(next);
    this.selectedChange.emit(next);
    this.filter.set('');
    if (this.multiple) {
      this.inputRef()?.nativeElement?.focus();
    } else {
      this.open.set(false);
    }
  }

  protected remove(v: string, event: Event): void {
    event.stopPropagation();
    const next = this._selected().filter((x) => x !== v);
    this._selected.set(next);
    this.selectedChange.emit(next);
  }

  @HostListener('document:pointerdown', ['$event'])
  protected onDocumentPointerDown(event: Event): void {
    if (!this.open()) return;
    const target = event.target as Node | null;
    if (target && !this.host.nativeElement.contains(target)) {
      this.open.set(false);
    }
  }

  @HostListener('document:keydown.escape')
  protected onEscape(): void {
    if (this.open()) this.open.set(false);
  }
}
