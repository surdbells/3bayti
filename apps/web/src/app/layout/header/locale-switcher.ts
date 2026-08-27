import { ChangeDetectionStrategy, Component, inject } from '@angular/core';
import { LocaleService, LOCALE_LABELS, LOCALES, Locale } from '../../core/i18n';

/**
 * Header locale switcher.
 *
 * Two-state toggle for Y.1: EN ⇄ AR. The current locale is displayed
 * with its native label (English / العربية); clicking switches to the
 * other one.
 *
 * Why a toggle rather than a dropdown
 * -----------------------------------
 * With only two locales a dropdown is more clicks than a toggle. If
 * we add a third locale in M4+ we'll swap this for a popover menu -
 * which is one change to this component, not the rest of the app.
 *
 * Accessibility
 * -------------
 * `aria-label` carries the explicit "Switch to <other locale>"
 * action; the visible label shows the LANGUAGE THE BUTTON SWITCHES
 * TO (not the current one) so users see what they'll get if they
 * click. The current locale is announced via the page's `<html lang>`
 * attribute managed by LocaleService.
 */
@Component({
  selector: 'app-locale-switcher',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <button
      type="button"
      class="locale-switcher"
      (click)="toggle()"
      [attr.aria-label]="ariaLabel()"
      data-testid="locale-switcher"
    >
      <span class="locale-switcher__label">{{ targetLabel() }}</span>
    </button>
  `,
  styles: [
    `
      .locale-switcher {
        appearance: none;
        background: transparent;
        border: 1px solid var(--color-border-subtle, #e2dccc);
        color: var(--color-text-primary, #2e241c);
        font-size: 13px;
        font-weight: 500;
        letter-spacing: 0.02em;
        padding: 6px 14px;
        border-radius: 9999px;
        cursor: pointer;
        transition: background 0.15s ease, color 0.15s ease, border-color 0.15s ease;
      }

      .locale-switcher:hover,
      .locale-switcher:focus-visible {
        background: var(--color-bg-muted, #f4f0ea);
        color: var(--color-brand-700, #5a3a2c);
        border-color: var(--color-brand-500, #b18f1f);
        outline: none;
      }

      .locale-switcher:focus-visible {
        outline: 2px solid var(--color-brand-500, #b18f1f);
        outline-offset: 2px;
      }
    `,
  ],
})
export class LocaleSwitcherComponent {
  private readonly locale = inject(LocaleService);

  /**
   * The OTHER locale, the one we'd switch to if clicked.
   * For two locales this is just "the one that isn't current".
   */
  private nextLocale(): Locale {
    const current = this.locale.current();
    const other = LOCALES.find(l => l !== current);
    /* Defensive: with two locales the find always succeeds, but
       TypeScript can't narrow that without a type-system gymnastic
       we don't need. Fall back to current (no-op) if somehow not. */
    return other ?? current;
  }

  /**
   * Label to display on the button, shows the language you'd
   * switch TO. So when current is EN we show "العربية"; when
   * current is AR we show "English".
   */
  protected targetLabel(): string {
    return LOCALE_LABELS[this.nextLocale()];
  }

  /**
   * Screen-reader announcement of the button's action.
   * Hard-coded EN strings because the button must be discoverable
   * by users who can't yet read the current page locale (which
   * is the whole point of having a switcher).
   */
  protected ariaLabel(): string {
    const target = this.nextLocale();
    return target === 'ar' ? 'Switch to Arabic' : 'Switch to English';
  }

  protected async toggle(): Promise<void> {
    await this.locale.setLocale(this.nextLocale());
  }
}
