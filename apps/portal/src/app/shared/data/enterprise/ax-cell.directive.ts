/**
 * Structural directives that let the host project rich cell content and a
 * row-expansion template into the enterprise table, addressed by column key.
 *
 *   <ng-template axCell="status" let-row let-i="index"> … </ng-template>
 *   <ng-template axRowExpand let-row> … </ng-template>
 */

import { Directive, Input, TemplateRef, inject } from '@angular/core';

@Directive({
  selector: '[axCell]',
  standalone: true,
})
export class AxCellDirective {
  /** The column key this template renders. */
  @Input('axCell') key!: string;
  readonly tpl = inject(TemplateRef<{ $implicit: unknown; index: number }>);
}

@Directive({
  selector: '[axRowExpand]',
  standalone: true,
})
export class AxRowExpandDirective {
  readonly tpl = inject(TemplateRef<{ $implicit: unknown }>);
}
