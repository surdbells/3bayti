import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * Inline SVG icons for the primary navigation.
 *
 * The web app ships no icon library, so nav glyphs are hand-authored
 * line icons (24×24, 1.7 stroke, currentColor) rendered by key. Shared
 * by both the desktop nav and the mobile drawer so the two stay in sync.
 *
 * Keys: 'categories' | 'styles' | 'stores' | 'bestSellers' | 'newArrivals'
 *       | 'gift' | 'user' | 'shoppingBag' | 'mapPin' | 'ruler' | 'heart'
 *       | 'lock' | 'mail' | 'lifeBuoy'.
 *
 * The account-hub tiles (Y.5) reuse this component for their leading
 * glyphs, which is why the set extends beyond the primary-nav keys.
 */
@Component({
  selector: 'app-nav-icon',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @switch (icon) {
      @case ('categories') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3.5" y="3.5" width="7" height="7" rx="1.6" />
          <rect x="13.5" y="3.5" width="7" height="7" rx="1.6" />
          <rect x="3.5" y="13.5" width="7" height="7" rx="1.6" />
          <rect x="13.5" y="13.5" width="7" height="7" rx="1.6" />
        </svg>
      }
      @case ('styles') {
        <!-- Garment on a hanger with a small sparkle — "Styles" / lookbook. -->
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 4.5a1.6 1.6 0 1 0 1.4 2.4c.3.6.1 1.2-.5 1.6L4 14.4a1 1 0 0 0 .5 1.85h15a1 1 0 0 0 .5-1.85l-7.5-4.4" />
          <path d="M18.5 4.2l.5 1.4 1.4.5-1.4.5-.5 1.4-.5-1.4-1.4-.5 1.4-.5z" />
        </svg>
      }
      @case ('stores') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M4 9v10a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1V9" />
          <path d="M2 9l2-5h16l2 5a2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0 2.5 2.5 0 0 1-5 0z" />
          <path d="M9 20v-5h6v5" />
        </svg>
      }
      @case ('bestSellers') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 3.2l2.6 5.3 5.8.8-4.2 4.1 1 5.8L12 16.5 6.8 19.2l1-5.8-4.2-4.1 5.8-.8z" />
        </svg>
      }
      @case ('newArrivals') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 2.5l1.9 6.4 6.6.2-5.2 4 1.8 6.4-5.1-3.9-5.1 3.9 1.8-6.4-5.2-4 6.6-.2z" />
        </svg>
      }
      @case ('gift') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="8" width="18" height="4" rx="1" />
          <path d="M5 12v8a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-8" />
          <path d="M12 8v13" />
          <path d="M12 8S10.5 4 8 4.5 8.5 8 12 8zM12 8s1.5-4 4-3.5S15.5 8 12 8z" />
        </svg>
      }
      @case ('user') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="8" r="3.6" />
          <path d="M5 20v-1a5 5 0 0 1 5-5h4a5 5 0 0 1 5 5v1" />
        </svg>
      }
      @case ('shoppingBag') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M5 7h14l-1.2 11.2a2 2 0 0 1-2 1.8H8.2a2 2 0 0 1-2-1.8L5 7z" />
          <path d="M9 7V5a3 3 0 0 1 6 0v2" />
        </svg>
      }
      @case ('mapPin') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 21s6.5-5.4 6.5-10.5a6.5 6.5 0 0 0-13 0C5.5 15.6 12 21 12 21z" />
          <circle cx="12" cy="10.5" r="2.4" />
        </svg>
      }
      @case ('ruler') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="2.8" y="8" width="18.4" height="8" rx="1.6"
                transform="rotate(-45 12 12)" />
          <path d="M9 7.5l1.5 1.5M11.5 5l1.5 1.5M14 2.5l1.5 1.5M6.5 10l1.5 1.5" />
        </svg>
      }
      @case ('heart') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <path d="M12 20s-7-4.6-7-9.4A4.1 4.1 0 0 1 12 7.5 4.1 4.1 0 0 1 19 10.6C19 15.4 12 20 12 20z" />
        </svg>
      }
      @case ('lock') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="4.5" y="10.5" width="15" height="9.5" rx="2" />
          <path d="M8 10.5V7.5a4 4 0 0 1 8 0v3" />
          <path d="M12 14.5v2.5" />
        </svg>
      }
      @case ('mail') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <rect x="3" y="5" width="18" height="14" rx="2" />
          <path d="m3.5 6.5 8.5 6 8.5-6" />
        </svg>
      }
      @case ('lifeBuoy') {
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor"
             stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <circle cx="12" cy="12" r="8.5" />
          <circle cx="12" cy="12" r="3.4" />
          <path d="M9.6 9.6 6 6M14.4 9.6 18 6M14.4 14.4 18 18M9.6 14.4 6 18" />
        </svg>
      }
    }
  `,
  styles: [
    `
      :host {
        display: inline-flex;
        align-items: center;
        line-height: 0;
      }
      svg {
        display: block;
      }
    `,
  ],
})
export class NavIconComponent {
  /** Icon key, selects which glyph to render. Unknown/empty → nothing. */
  @Input() icon = '';
}
