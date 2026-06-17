import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  HostListener,
  ElementRef,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  CurrencyService,
  SUPPORTED_CURRENCIES,
  CURRENCY_LABELS,
  type SupportedCurrency,
} from '../../core/currency/currency.service';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * CurrencySwitcherComponent — a compact dropdown in the site header
 * that lets the visitor choose their preferred display currency.
 *
 * Lives beside app-locale-switcher; same pill-button visual treatment
 * for visual parity. The dropdown panel closes on outside clicks and
 * on selection. All options are keyboard-navigable.
 *
 * AED is always listed first (it's the settlement currency); others
 * follow in the defined order (USD, EUR, SAR, GBP).
 *
 * Accessibility: button has aria-haspopup="listbox" + aria-expanded;
 * the listbox has role="listbox"; each option has role="option" +
 * aria-selected.
 */
@Component({
  selector: 'app-currency-switcher',
  standalone: true,
  imports: [CommonModule, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="currency-switcher">
      <button
        type="button"
        class="currency-switcher__trigger"
        [class.currency-switcher__trigger--open]="open()"
        (click)="toggle()"
        aria-haspopup="listbox"
        [attr.aria-expanded]="open()"
        [attr.aria-label]="'header.currencyAria' | translate:{ currency: currency() }"
        data-testid="currency-switcher">
        {{ currency() }}
        <span class="currency-switcher__chevron" aria-hidden="true">▾</span>
      </button>

      @if (open()) {
        <ul
          class="currency-switcher__dropdown"
          role="listbox"
          [attr.aria-label]="'header.chooseCurrency' | translate">
          @for (code of currencies; track code) {
            <li
              class="currency-switcher__option"
              [class.currency-switcher__option--active]="code === currency()"
              role="option"
              [attr.aria-selected]="code === currency()"
              (click)="select(code)"
              (keydown.enter)="select(code)"
              (keydown.space)="select(code)"
              tabindex="0">
              {{ label(code) }}
              @if (code === currency()) {
                <span class="currency-switcher__tick" aria-hidden="true">✓</span>
              }
            </li>
          }
        </ul>
      }
    </div>
  `,
  styles: [`
    .currency-switcher {
      position: relative;
    }

    .currency-switcher__trigger {
      appearance: none;
      display: flex;
      align-items: center;
      gap: 4px;
      background: transparent;
      border: 1px solid var(--color-border-subtle, #e2dccc);
      color: var(--color-text-primary, #2e241c);
      font-size: 13px;
      font-weight: 500;
      letter-spacing: 0.02em;
      padding: 6px 12px;
      border-radius: 9999px;
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease;
      white-space: nowrap;
    }

    .currency-switcher__trigger:hover,
    .currency-switcher__trigger:focus-visible,
    .currency-switcher__trigger--open {
      background: var(--color-bg-muted, #f4f0ea);
      border-color: var(--color-brand-500, #b18f1f);
      outline: none;
    }

    .currency-switcher__chevron {
      font-size: 10px;
      opacity: 0.6;
    }

    .currency-switcher__dropdown {
      position: absolute;
      top: calc(100% + 6px);
      right: 0;
      z-index: 200;
      min-width: 148px;
      list-style: none;
      margin: 0;
      padding: 6px 0;
      background: var(--color-bg-surface, #fff);
      border: 1px solid var(--color-border-default, rgba(46,36,28,0.16));
      border-radius: 10px;
      box-shadow: var(--shadow-floating,
        0 1px 2px rgba(90,58,44,0.04),
        0 6px 14px -4px rgba(90,58,44,0.08));
    }

    .currency-switcher__option {
      display: flex;
      align-items: center;
      justify-content: space-between;
      padding: 8px 16px;
      font-size: 13px;
      color: var(--color-text-primary, #2e241c);
      cursor: pointer;
      transition: background 0.1s ease;

      &:hover,
      &:focus-visible {
        background: var(--color-bg-muted, #f4f0ea);
        outline: none;
      }
    }

    .currency-switcher__option--active {
      font-weight: 600;
      color: var(--color-brand-700, #5a3a2c);
    }

    .currency-switcher__tick {
      color: var(--color-brand-500, #b18f1f);
      font-size: 12px;
    }
  `],
})
export class CurrencySwitcherComponent {
  private readonly svc = inject(CurrencyService);
  private readonly el = inject(ElementRef);

  readonly currencies = SUPPORTED_CURRENCIES;
  readonly currency = this.svc.currency;
  readonly open = signal(false);

  @HostListener('document:click', ['$event.target'])
  onDocClick(target: EventTarget | null): void {
    if (this.open() && target instanceof Node && !this.el.nativeElement.contains(target)) {
      this.open.set(false);
    }
  }

  label(code: SupportedCurrency): string {
    return CURRENCY_LABELS[code];
  }

  toggle(): void { this.open.update((v) => !v); }
  close(): void  { this.open.set(false); }

  select(code: SupportedCurrency): void {
    this.svc.set(code);
    this.open.set(false);
  }
}
