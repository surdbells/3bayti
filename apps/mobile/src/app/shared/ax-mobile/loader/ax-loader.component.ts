/**
 * AxLoaderComponent — abayti's spinner primitive.
 *
 * Three dots pulsing in a left-to-right wave. Brand brown by default,
 * inheriting via currentColor so callers can override with text color
 * inheritance or an explicit `color` input.
 *
 * Usage:
 *
 *   <ax-loader></ax-loader>
 *   <ax-loader size="lg"></ax-loader>
 *   <ax-loader size="sm" color="var(--ax-color-text-tertiary)"></ax-loader>
 *
 *   <button>
 *     <ax-loader size="sm"></ax-loader>
 *     Saving...
 *   </button>
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';

import type { AxLoaderSize } from './ax-loader.types';

@Component({
  selector: 'ax-loader',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-loader.component.html',
  styleUrl: './ax-loader.component.scss',
})
export class AxLoaderComponent {
  /** Size token. Default 'md' (6px dots, 48px wide). */
  @Input() size: AxLoaderSize = 'md';

  /** Optional CSS color value. If omitted, inherits via currentColor. */
  @Input() color?: string;

  /** Accessible label for screen readers. Defaults to 'Loading'. */
  @Input() label = 'Loading';
}
