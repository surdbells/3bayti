import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * Inline SVG icons for the primary navigation.
 *
 * The web app ships no icon library, so nav glyphs are hand-authored
 * line icons (24×24, 1.7 stroke, currentColor) rendered by key. Shared
 * by both the desktop nav and the mobile drawer so the two stay in sync.
 *
 * Keys: 'categories' | 'stores' | 'bestSellers' | 'newArrivals' | 'gift'.
 * ('gift' is authored ahead of the Phase E gift-card nav entry.)
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
          <rect x="3" y="3" width="7" height="7" rx="1.5" />
          <rect x="14" y="3" width="7" height="7" rx="1.5" />
          <rect x="3" y="14" width="7" height="7" rx="1.5" />
          <rect x="14" y="14" width="7" height="7" rx="1.5" />
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
  /** Icon key — selects which glyph to render. Unknown/empty → nothing. */
  @Input() icon = '';
}
