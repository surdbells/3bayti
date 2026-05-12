import {
  AfterContentInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ContentChildren,
  EventEmitter,
  Input,
  OnChanges,
  Output,
  QueryList,
  SimpleChanges,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { AxColumnComponent } from './ax-column.component';

export type AxSortDirection = 'asc' | 'desc' | null;
export interface AxSortState {
  key: string | null;
  direction: AxSortDirection;
}

/**
 * Client-side data table.
 *
 * Columns are declared as children via <app-ax-column>. The data array is
 * sorted + paginated client-side based on user interaction (sort header
 * clicks, page size changes via the optional <app-ax-pagination> sibling).
 *
 * Minimal usage:
 *   <app-ax-table [data]="orders" trackBy="id">
 *     <app-ax-column key="order_ref" label="Order #" sortable>
 *       <ng-template axCell let-row>{{ row.order_ref }}</ng-template>
 *     </app-ax-column>
 *     <app-ax-column key="status" label="Status">
 *       <ng-template axCell let-row>
 *         <span class="ax-badge ax-badge-success">{{ row.status }}</span>
 *       </ng-template>
 *     </app-ax-column>
 *   </app-ax-table>
 *
 * With selection + pagination:
 *   <app-ax-table [data]="orders" [selectable]="true"
 *                 [(selection)]="selected" (rowClick)="viewOrder($event)"
 *                 [pageSize]="20">
 *     ...columns...
 *   </app-ax-table>
 */
@Component({
  selector: 'app-ax-table',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ax-table-wrapper">
      <table
        class="ax-table"
        [class.ax-table-hover]="hover"
        [class.ax-table-interactive]="interactive"
        [class.ax-table-compact]="compact"
        [class.ax-table-responsive]="responsive"
      >
        <thead>
          <tr>
            <th *ngIf="selectable" class="ax-th-select" scope="col">
              <label class="ax-check" [attr.aria-label]="'Select all rows'">
                <input
                  type="checkbox"
                  [checked]="allVisibleSelected()"
                  [indeterminate]="someVisibleSelected()"
                  (change)="toggleAll($event)"
                />
                <span class="ax-check-box"></span>
              </label>
            </th>
            <th
              *ngFor="let col of columns; trackBy: trackByKey"
              scope="col"
              [style.width]="col.width || null"
              [class.ax-td-right]="col.align === 'right'"
              [class.ax-td-center]="col.align === 'center'"
              [class.ax-th-sortable]="col.isSortable"
              [class.ax-th-sort-asc]="sort.key === col.key && sort.direction === 'asc'"
              [class.ax-th-sort-desc]="sort.key === col.key && sort.direction === 'desc'"
              [class.ax-lg-hidden]="false"
              (click)="col.isSortable && onSortToggle(col.key)"
              (keydown.enter)="col.isSortable && onSortToggle(col.key)"
              [attr.tabindex]="col.isSortable ? 0 : null"
              [attr.aria-sort]="ariaSortFor(col.key)"
            >
              {{ col.resolvedLabel }}
              <span *ngIf="col.isSortable" class="ax-th-sort-icon" aria-hidden="true">
                <span class="material-symbols-outlined">{{ sortIconFor(col.key) }}</span>
              </span>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            *ngFor="let row of visibleRows; let i = index; trackBy: trackByRow"
            [class.ax-tr-selected]="isSelected(row)"
            (click)="onRowClick(row, $event)"
          >
            <td *ngIf="selectable" class="ax-td-select" (click)="$event.stopPropagation()">
              <label class="ax-check" [attr.aria-label]="'Select row'">
                <input
                  type="checkbox"
                  [checked]="isSelected(row)"
                  (change)="toggleRow(row, $event)"
                />
                <span class="ax-check-box"></span>
              </label>
            </td>
            <td
              *ngFor="let col of columns; trackBy: trackByKey"
              [attr.data-label]="col.resolvedLabel"
              [class.ax-td-right]="col.align === 'right'"
              [class.ax-td-center]="col.align === 'center'"
            >
              <ng-container *ngIf="col.cellTpl; else plainCell"
                [ngTemplateOutlet]="col.cellTpl"
                [ngTemplateOutletContext]="{ $implicit: row, index: i }">
              </ng-container>
              <ng-template #plainCell>{{ getCellValue(row, col.key) }}</ng-template>
            </td>
          </tr>
          <tr *ngIf="!loading && !visibleRows.length">
            <td [attr.colspan]="columnSpan" class="ax-table-empty">
              <div class="ax-empty">
                <span class="ax-empty-icon">
                  <span class="material-symbols-outlined" aria-hidden="true">inbox</span>
                </span>
                <h3 class="ax-empty-title">{{ emptyTitle }}</h3>
                <p class="ax-empty-description">{{ emptyDescription }}</p>
              </div>
            </td>
          </tr>
        </tbody>
      </table>

      <div *ngIf="loading" class="ax-table-loading-overlay">
        <span class="ax-spinner ax-spinner-lg" aria-label="Loading"></span>
      </div>
    </div>
  `,
})
export class AxTableComponent<T = any> implements AfterContentInit, OnChanges {
  /** Data to render. */
  @Input() data: T[] = [];

  /** Show hover state on rows. */
  @Input() hover = true;

  /** Rows are clickable (cursor pointer). Pair with (rowClick). */
  @Input() interactive = false;

  /** Smaller row padding. */
  @Input() compact = false;

  /** Stack rows as cards below 768px. */
  @Input() responsive = true;

  /** Enable multi-row selection via checkbox column. */
  @Input() selectable = false;

  /** Selection (bound via [(selection)]). Array of selected rows. */
  @Input() selection: T[] = [];
  @Output() selectionChange = new EventEmitter<T[]>();

  /** Loading spinner overlay. */
  @Input() loading = false;

  /** Initial sort state. */
  @Input() sort: AxSortState = { key: null, direction: null };
  @Output() sortChange = new EventEmitter<AxSortState>();

  /** Client-side page size. 0/undefined = no pagination. */
  @Input() pageSize = 0;
  @Input() pageIndex = 0;
  @Output() pageIndexChange = new EventEmitter<number>();

  /** trackBy key — row[field] used as identity. Defaults to 'id' if present. */
  @Input() trackBy: string = 'id';

  /** Empty state copy. */
  @Input() emptyTitle = 'No results';
  @Input() emptyDescription = 'Nothing to show here yet.';

  @Output() rowClick = new EventEmitter<T>();

  @ContentChildren(AxColumnComponent) columnList!: QueryList<AxColumnComponent>;
  columns: AxColumnComponent[] = [];

  /** Sorted + paginated view of data. Recomputed on change. */
  visibleRows: T[] = [];

  /** Sorted-only view (used for select-all semantics & CSV export). */
  private sortedRows: T[] = [];

  private readonly cdr = inject(ChangeDetectorRef);

  ngAfterContentInit(): void {
    this.columns = this.columnList.toArray();
    this.columnList.changes.subscribe(() => {
      this.columns = this.columnList.toArray();
      this.cdr.markForCheck();
    });
    this.recompute();
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['data'] || changes['sort'] || changes['pageSize'] || changes['pageIndex']) {
      this.recompute();
    }
  }

  // -------- Sort --------

  onSortToggle(key: string): void {
    let dir: AxSortDirection;
    if (this.sort.key !== key) {
      dir = 'asc';
    } else if (this.sort.direction === 'asc') {
      dir = 'desc';
    } else if (this.sort.direction === 'desc') {
      dir = null;
    } else {
      dir = 'asc';
    }
    this.sort = { key: dir ? key : null, direction: dir };
    this.sortChange.emit(this.sort);
    this.pageIndex = 0;
    this.pageIndexChange.emit(0);
    this.recompute();
  }

  sortIconFor(key: string): string {
    if (this.sort.key !== key) return 'unfold_more';
    return this.sort.direction === 'asc' ? 'arrow_upward' : 'arrow_downward';
  }

  ariaSortFor(key: string): string | null {
    if (this.sort.key !== key) return 'none';
    return this.sort.direction === 'asc' ? 'ascending'
         : this.sort.direction === 'desc' ? 'descending'
         : 'none';
  }

  // -------- Selection --------

  isSelected(row: T): boolean {
    return this.selection.some(r => this.identify(r) === this.identify(row));
  }

  toggleRow(row: T, event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    if (checked) {
      this.selection = [...this.selection, row];
    } else {
      const id = this.identify(row);
      this.selection = this.selection.filter(r => this.identify(r) !== id);
    }
    this.selectionChange.emit(this.selection);
    this.cdr.markForCheck();
  }

  allVisibleSelected(): boolean {
    if (!this.visibleRows.length) return false;
    return this.visibleRows.every(r => this.isSelected(r));
  }

  someVisibleSelected(): boolean {
    const some = this.visibleRows.some(r => this.isSelected(r));
    return some && !this.allVisibleSelected();
  }

  toggleAll(event: Event): void {
    const checked = (event.target as HTMLInputElement).checked;
    const visibleIds = new Set(this.visibleRows.map(r => this.identify(r)));
    if (checked) {
      const toAdd = this.visibleRows.filter(r => !this.isSelected(r));
      this.selection = [...this.selection, ...toAdd];
    } else {
      this.selection = this.selection.filter(r => !visibleIds.has(this.identify(r)));
    }
    this.selectionChange.emit(this.selection);
    this.cdr.markForCheck();
  }

  // -------- Row events --------

  onRowClick(row: T, event: MouseEvent): void {
    // Ignore clicks that originated from interactive elements inside cells.
    const target = event.target as HTMLElement | null;
    if (target?.closest('button, a, input, select, textarea, [role="button"]')) {
      return;
    }
    this.rowClick.emit(row);
  }

  // -------- Tracking --------

  trackByKey = (_: number, col: AxColumnComponent) => col.key;

  trackByRow = (index: number, row: T): unknown => {
    const id = this.identify(row);
    return id ?? index;
  };

  private identify(row: T): unknown {
    return (row as any)?.[this.trackBy];
  }

  get columnSpan(): number {
    return this.columns.length + (this.selectable ? 1 : 0);
  }

  /** Safely read a value from a row by key. Used by the default fallback cell. */
  getCellValue(row: T, key: string): any {
    return row == null ? '' : (row as any)[key];
  }

  // -------- Core recompute (sort + paginate) --------

  private recompute(): void {
    // Sort
    if (this.sort.key && this.sort.direction) {
      const col = this.columns.find(c => c.key === this.sort.key);
      const dir = this.sort.direction === 'asc' ? 1 : -1;
      this.sortedRows = [...this.data].sort((a, b) => {
        const av = col ? col.accessor(a) : (a as any)?.[this.sort.key!];
        const bv = col ? col.accessor(b) : (b as any)?.[this.sort.key!];
        return this.compare(av, bv) * dir;
      });
    } else {
      this.sortedRows = [...this.data];
    }

    // Paginate
    if (this.pageSize && this.pageSize > 0) {
      const start = this.pageIndex * this.pageSize;
      this.visibleRows = this.sortedRows.slice(start, start + this.pageSize);
    } else {
      this.visibleRows = this.sortedRows;
    }
    this.cdr.markForCheck();
  }

  private compare(a: any, b: any): number {
    // Null-last
    if (a == null && b == null) return 0;
    if (a == null) return 1;
    if (b == null) return -1;
    if (a instanceof Date && b instanceof Date) return a.getTime() - b.getTime();
    if (typeof a === 'number' && typeof b === 'number') return a - b;
    return String(a).localeCompare(String(b), undefined, { numeric: true, sensitivity: 'base' });
  }

  // -------- Public helpers --------

  /** Returns the number of rows total (pre-pagination). */
  get totalCount(): number {
    return this.data.length;
  }

  /** Clear selection. */
  clearSelection(): void {
    this.selection = [];
    this.selectionChange.emit(this.selection);
    this.cdr.markForCheck();
  }

  /** Simple CSV export of the sorted (all pages) dataset using current columns. */
  exportCsv(filename = 'export.csv'): void {
    const headers = this.columns.map(c => this.csvEscape(c.resolvedLabel)).join(',');
    const rows = this.sortedRows
      .map(row =>
        this.columns
          .map(col => this.csvEscape(String(col.accessor(row) ?? '')))
          .join(','),
      )
      .join('\n');
    const csv = `${headers}\n${rows}`;

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
  }

  private csvEscape(value: string): string {
    if (/[",\n]/.test(value)) {
      return `"${value.replace(/"/g, '""')}"`;
    }
    return value;
  }
}
