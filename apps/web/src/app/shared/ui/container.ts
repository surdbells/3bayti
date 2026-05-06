import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * Container — width-constrained content wrapper. The single most-used
 * layout primitive on the site. Sets max-width + horizontal padding +
 * margin auto. Three preset widths cover ~95% of needs.
 *
 * Usage:
 *   <ui-container>...</ui-container>           (default 'wide')
 *   <ui-container size="narrow">...</ui-container>
 *   <ui-container size="full">...</ui-container>
 *
 * Sizes:
 *   narrow  640px  — single-column reading content (about, blog post)
 *   default 1024px — most page content
 *   wide    1280px — marketing pages, dashboards
 *   full    100%   — edge-to-edge sections (hero backgrounds, etc.)
 */
@Component({
  selector: 'ui-container',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<ng-content></ng-content>`,
  styles: [`
    :host {
      display: block;
      width: 100%;
      margin-inline: auto;
      padding-inline: 24px;
    }
    :host([data-size='narrow']) { max-width: 640px; }
    :host([data-size='default']) { max-width: 1024px; }
    :host([data-size='wide']) { max-width: 1280px; }
    :host([data-size='full']) { max-width: 100%; padding-inline: 0; }
  `],
  host: {
    '[attr.data-size]': 'size',
  },
})
export class ContainerComponent {
  @Input() size: 'narrow' | 'default' | 'wide' | 'full' = 'wide';
}
