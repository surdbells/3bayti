import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { RouterLink } from '@angular/router';

/**
 * SectionHeader, the consistent header used above homepage marketing
 * sections (bento categories, campaign rails, recommendations).
 *
 * Encodes the page's section-title language in one place: an optional
 * uppercase eyebrow, a Playfair display title, an optional supporting
 * line, and an optional "view all" CTA aligned to the inline-end. The
 * existing ProductStrip carries its own header; this primitive gives the
 * NEW sections the same rhythm without coupling to it.
 *
 * RTL-safe: spacing uses logical properties and the CTA arrow mirrors
 * under [dir='rtl'].
 */
@Component({
  selector: 'ui-section-header',
  standalone: true,
  imports: [RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <header class="sh" [class.sh--center]="align === 'center'">
      <div class="sh__text">
        @if (eyebrow) {
          <p class="sh__eyebrow">{{ eyebrow }}</p>
        }
        <h2 class="sh__title">{{ title }}</h2>
        @if (subtitle) {
          <p class="sh__subtitle">{{ subtitle }}</p>
        }
      </div>

      @if (ctaLink) {
        <a [routerLink]="ctaLink" class="sh__cta">
          {{ ctaLabel || 'View all' }}
          <svg viewBox="0 0 24 24" aria-hidden="true" class="sh__cta-arrow">
            <path d="M5 12h14M13 5l7 7-7 7" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </a>
      }
    </header>
  `,
  styles: [`
    .sh {
      display: flex;
      align-items: flex-end;
      justify-content: space-between;
      gap: var(--space-lg);
      margin-block-end: var(--space-lg);
    }
    .sh--center {
      flex-direction: column;
      align-items: center;
      text-align: center;
    }
    .sh__eyebrow {
      margin: 0 0 var(--space-xs);
      font-size: var(--text-eyebrow);
      font-weight: 600;
      letter-spacing: 0.14em;
      text-transform: uppercase;
      color: var(--color-brand-600);
    }
    .sh__title {
      margin: 0;
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-weight: 600;
      font-size: var(--text-section-title);
      line-height: 1.1;
      color: var(--color-brand-700);
    }
    .sh__subtitle {
      margin: var(--space-xs) 0 0;
      max-width: 54ch;
      font-size: 0.95rem;
      line-height: 1.5;
      color: var(--color-text-secondary);
    }
    .sh__cta {
      display: inline-flex;
      align-items: center;
      gap: var(--space-xs);
      flex-shrink: 0;
      white-space: nowrap;
      font-weight: 600;
      font-size: 0.9rem;
      color: var(--color-brand-700);
      text-decoration: none;
      padding-block: var(--space-2xs);
      border-block-end: 1.5px solid transparent;
      transition:
        border-color var(--duration-base) var(--ease-out),
        gap var(--duration-base) var(--ease-out);
    }
    .sh__cta:hover {
      border-block-end-color: var(--color-brand-500);
      gap: calc(var(--space-xs) + 3px);
    }
    .sh__cta:focus-visible {
      outline: 2px solid var(--color-brand-500);
      outline-offset: 3px;
      border-radius: 2px;
    }
    .sh__cta-arrow {
      width: 18px;
      height: 18px;
    }
    :host-context([dir='rtl']) .sh__cta-arrow {
      transform: scaleX(-1);
    }
    @media (max-width: 640px) {
      .sh { align-items: flex-start; }
      .sh__cta { font-size: 0.85rem; }
    }
    @media (prefers-reduced-motion: reduce) {
      .sh__cta { transition: none; }
      .sh__cta:hover { gap: var(--space-xs); }
    }
  `],
})
export class SectionHeaderComponent {
  @Input() eyebrow = '';
  @Input() title = '';
  @Input() subtitle = '';
  @Input() ctaLabel = '';
  @Input() ctaLink: string | unknown[] | null = null;
  @Input() align: 'start' | 'center' = 'start';
}
