import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * Text — body copy primitive.
 *
 * Renders as <p> by default, or <span> when `inline` is set (for inline
 * text inside other elements where a block-level <p> would break layout).
 *
 * Sizes:
 *   xs    12px  — captions, fine print
 *   sm    13px  — secondary metadata
 *   base  15px  — default body text
 *   lg    17px  — lede paragraphs, callouts
 *
 * Tones:
 *   default     primary text color (high-contrast)
 *   secondary   muted (descriptions, helper text)
 *   tertiary    even more muted (timestamps, meta)
 *   inverse     for use on dark backgrounds (footer, etc.)
 */
@Component({
  selector: 'ui-text',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (inline) {
      <span [class]="cssClass()"><ng-content /></span>
    } @else {
      <p [class]="cssClass()"><ng-content /></p>
    }
  `,
  styles: [`
    :host { display: block; }
    :host([data-inline='true']) { display: inline; }

    p, span {
      margin: 0;
      font-family: inherit;
      line-height: 1.6;
    }

    .size-xs   { font-size: 12px; line-height: 1.5; }
    .size-sm   { font-size: 13px; line-height: 1.55; }
    .size-base { font-size: 15px; }
    .size-lg   { font-size: 17px; line-height: 1.65; }

    .tone-default   { color: var(--color-text-primary); }
    .tone-secondary { color: var(--color-text-secondary); }
    .tone-tertiary  { color: var(--color-text-tertiary); }
    .tone-inverse   { color: var(--color-text-inverse); }
  `],
  host: {
    '[attr.data-inline]': 'inline',
  },
})
export class TextComponent {
  @Input() size: 'xs' | 'sm' | 'base' | 'lg' = 'base';
  @Input() tone: 'default' | 'secondary' | 'tertiary' | 'inverse' = 'default';
  @Input() inline = false;

  cssClass(): string {
    return `size-${this.size} tone-${this.tone}`;
  }
}
