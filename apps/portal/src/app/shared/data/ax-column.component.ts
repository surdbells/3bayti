import { Directive, Input, TemplateRef, ContentChild, inject } from '@angular/core';

export type AxColumnAlign = 'left' | 'right' | 'center';

/**
 * Column definition marker directive. Placed inside <app-ax-table>, one per
 * visible column. Carries the column's metadata and (via its content template)
 * how each cell should render.
 *
 *   <app-ax-column [key]="'name'" label="Product name" sortable>
 *     <ng-template axCell let-row>
 *       <strong>{{ row.name }}</strong>
 *     </ng-template>
 *   </app-ax-column>
 */
@Directive({
  selector: 'app-ax-column',
  standalone: true,
})
export class AxColumnComponent {
  /** Unique key. Also used as the default `label` if label is missing,
   *  and as the sort comparator field when `sortAccessor` isn't provided. */
  @Input() key!: string;

  /** Header label. Falls back to `key` if absent. */
  @Input() label?: string;

  /** Column is sortable when this is truthy. */
  @Input() sortable: boolean | '' = false;

  /** Horizontal alignment of cell content + header. */
  @Input() align: AxColumnAlign = 'left';

  /** Width (any CSS size) — default auto. */
  @Input() width?: string;

  /** Hide this column on mobile (responsive stacking still works for others). */
  @Input() hideOnMobile = false;

  /** Custom sort accessor. Given the row object, return the value to sort by. */
  @Input() sortAccessor?: (row: any) => string | number | Date | null | undefined;

  /** Cell template projected from the user. */
  @ContentChild(TemplateRef) cellTpl?: TemplateRef<{ $implicit: any; index: number }>;

  get resolvedLabel(): string {
    return this.label ?? this.key;
  }

  get isSortable(): boolean {
    return this.sortable === true || this.sortable === '';
  }

  /** Accessor used for sort comparisons. */
  accessor(row: any): string | number | Date | null | undefined {
    if (this.sortAccessor) return this.sortAccessor(row);
    return row?.[this.key];
  }
}
