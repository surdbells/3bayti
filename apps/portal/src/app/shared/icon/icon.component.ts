import { ChangeDetectionStrategy, Component, Input, computed, signal } from '@angular/core';
import { LucideAngularModule, Circle, type LucideIconData } from 'lucide-angular';
import { ICON_MAP } from './icon-map';

/**
 * App-wide icon. Drop-in replacement for the old
 * `<app-icon name="name"></app-icon>` glyphs.
 *
 * Keeps the Material Symbols names as its public API (see ICON_MAP), so
 * the migration was a tag swap and the name→glyph mapping lives in one
 * place. Renders a Lucide SVG sized to 1em with `currentColor`, so every
 * existing `font-size` style and colour class (ax-text-brand, etc.) keeps
 * working untouched. Unknown names fall back to a neutral dot rather than
 * throwing.
 *
 * Usage:
 *   <app-icon name="edit" />
 *   <app-icon name="save" class="ax-text-brand" style="font-size:18px" />
 *   <app-icon [name]="dynamicName" />
 */
@Component({
  selector: 'app-icon',
  standalone: true,
  imports: [LucideAngularModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `<lucide-icon [img]="icon()" [class]="svgClass" aria-hidden="true"></lucide-icon>`,
  styles: [`
    :host {
      display: inline-flex;
      align-items: center;
      justify-content: center;
      line-height: 1;
      /* Inherit sizing from font-size so legacy inline styles work. */
      width: 1em;
      height: 1em;
      vertical-align: -0.125em;
    }
    :host ::ng-deep svg {
      width: 1em;
      height: 1em;
      display: block;
      stroke: currentColor;
    }
  `],
})
export class IconComponent {
  /** Material Symbols name (legacy API), resolved via ICON_MAP. */
  @Input({ required: true })
  set name(value: string) { this._name.set(value ?? ''); }
  private readonly _name = signal('');

  /** Optional passthrough class for the inner SVG. */
  @Input() svgClass = '';

  readonly icon = computed<LucideIconData>(() => ICON_MAP[this._name()] ?? Circle);
}
