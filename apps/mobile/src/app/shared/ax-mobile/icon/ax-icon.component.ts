/**
 * AxIconComponent — abayti's icon primitive.
 *
 * Thin wrapper around lucide-angular's <lucide-icon> with M2-aligned
 * token defaults: editorial stroke 1.5, size tokens xs/sm/md/lg/xl,
 * currentColor inheritance.
 *
 * Usage:
 *
 *   <ax-icon name="arrow-left"></ax-icon>
 *   <ax-icon name="shopping-bag" size="lg"></ax-icon>
 *   <ax-icon name="heart" [size]="28"></ax-icon>
 *   <ax-icon name="check" color="var(--ax-palette-success-500)"></ax-icon>
 *   <ax-icon name="alert-circle" strokeWidth="bold"></ax-icon>
 *
 * Implementation note: lucide-angular's <lucide-icon [name]> path
 * runs the input through toPascalCase() before looking up icons.
 * That assumes icons are registered under PascalCase keys (e.g. 'Heart').
 * We register under kebab-case ('heart') so callers' templates stay
 * consistent with the rest of the M2 design system. To avoid lucide's
 * built-in lookup, this component resolves the icon symbol from
 * AX_ICON_SET directly and passes it to lucide via [img], bypassing
 * the name lookup entirely.
 *
 * See docs/m4-icons-loader-skeleton-spec.md for design rationale.
 */

import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { LucideAngularModule, type LucideIconData } from 'lucide-angular';

import {
  AX_ICON_SIZES,
  AX_ICON_STROKES,
  type AxIconSize,
  type AxIconStrokeWidth,
} from './ax-icon.types';
import { AX_ICON_SET } from './icon-set';

@Component({
  selector: 'ax-icon',
  standalone: true,
  imports: [CommonModule, LucideAngularModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<lucide-icon
    *ngIf="resolvedIcon as ico"
    [img]="ico"
    [size]="resolvedSize"
    [strokeWidth]="resolvedStrokeWidth"
    [style.color]="color || null"
  ></lucide-icon>`,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 0;
    }
  `],
})
export class AxIconComponent {
  /** Lucide icon name (kebab-case). Required. */
  @Input({ required: true }) name!: string;

  /** Size token or numeric pixel value. Default 'md' (20px). */
  @Input() size: AxIconSize | number = 'md';

  /** Stroke width token or numeric. Default 'regular' (1.5). */
  @Input() strokeWidth: AxIconStrokeWidth | number = 'regular';

  /** Optional CSS color value. If omitted, inherits via currentColor. */
  @Input() color?: string;

  get resolvedSize(): number {
    return typeof this.size === 'number' ? this.size : AX_ICON_SIZES[this.size];
  }

  get resolvedStrokeWidth(): number {
    return typeof this.strokeWidth === 'number'
      ? this.strokeWidth
      : AX_ICON_STROKES[this.strokeWidth];
  }

  /**
   * Resolves the icon symbol from AX_ICON_SET. Returns undefined if
   * the name isn't registered — the *ngIf in the template prevents
   * <lucide-icon> from being created at all in that case (avoids
   * lucide throwing 'icon not provided' which cascades into routing
   * errors in Ionic).
   */
  get resolvedIcon(): LucideIconData | undefined {
    return AX_ICON_SET[this.name];
  }
}
