import {
  Injectable,
  Signal,
  signal,
  computed,
} from '@angular/core';

/** ISO 4217 codes supported for display (mirroring the backend Currency enum, X.15). */
export const SUPPORTED_CURRENCIES = ['AED', 'USD', 'EUR', 'SAR', 'GBP'] as const;
export type SupportedCurrency = (typeof SUPPORTED_CURRENCIES)[number];

/** Display labels shown in the header switcher. */
export const CURRENCY_LABELS: Record<SupportedCurrency, string> = {
  AED: 'AED د.إ',
  USD: 'USD $',
  EUR: 'EUR €',
  SAR: 'SAR ﷼',
  GBP: 'GBP £',
};

const STORAGE_KEY = 'bayti_currency';
const DEFAULT: SupportedCurrency = 'AED';

/**
 * CurrencyService, persists the visitor's chosen display currency and
 * exposes it as a signal so catalog services can thread ?currency=XXX
 * through their API calls (M3.2.W.3).
 *
 * AED is the canonical settlement currency for all carts, orders, and
 * payments; the other codes are DISPLAY ONLY. The checkout always
 * charges in AED regardless of the display preference.
 *
 * The choice is persisted in localStorage so it survives page reloads
 * and tab switches. localStorage access is wrapped in try/catch so a
 * blocked-storage environment degrades to the default rather than
 * throwing.
 */
@Injectable({ providedIn: 'root' })
export class CurrencyService {
  private readonly _currency = signal<SupportedCurrency>(this.readInitial());

  /** Current display currency. */
  readonly currency: Signal<SupportedCurrency> = this._currency.asReadonly();

  /** True when the display currency differs from the settlement currency. */
  readonly isConverted = computed(() => this._currency() !== 'AED');

  /** Query param value to append to catalog reads; empty string when AED. */
  readonly queryParam = computed(() =>
    this._currency() === 'AED' ? '' : this._currency(),
  );

  set(code: SupportedCurrency): void {
    if (!SUPPORTED_CURRENCIES.includes(code)) return;
    this._currency.set(code);
    try { localStorage.setItem(STORAGE_KEY, code); } catch { /* storage unavailable */ }
  }

  private readInitial(): SupportedCurrency {
    try {
      const saved = localStorage.getItem(STORAGE_KEY) as SupportedCurrency | null;
      return saved && (SUPPORTED_CURRENCIES as readonly string[]).includes(saved) ? saved : DEFAULT;
    } catch {
      return DEFAULT;
    }
  }
}
