/**
 * Enterprise data table, config-driven host component.
 *
 * Wires together:
 *   - an AxDataSource<T> (client or server)            → data + loading/error
 *   - the AxDataTableConfig<T>                          → columns, actions, …
 *   - the AxExportService                              → CSV / XLSX / PDF
 *   - cell templates projected by the host             → rich rendering
 *
 * The host supplies cell templates by key:
 *
 *   <app-ax-data-table [config]="cfg" [dataSource]="ds">
 *     <ng-template axCell="status" let-row>
 *       <span class="ax-badge">{{ row.status }}</span>
 *     </ng-template>
 *     <ng-template axRowExpand let-row>…detail…</ng-template>
 *   </app-ax-data-table>
 *
 * Columns without a matching template fall back to value/format from the
 * column def. Everything is OnPush; query mutations flow through a single
 * signal so change detection stays cheap.
 */

import {
  AfterContentInit,
  ChangeDetectionStrategy,
  Component,
  ContentChildren,
  DestroyRef,
  EventEmitter,
  Input,
  OnChanges,
  OnDestroy,
  Output,
  QueryList,
  SimpleChanges,
  TemplateRef,
  computed,
  inject,
  signal,
} from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { BehaviorSubject } from 'rxjs';

import { AxCellDirective, AxRowExpandDirective } from './ax-cell.directive';
import { AxDropdownDirective, AxDropdownItemDirective } from '../../overlays/ax-dropdown.directive';
import { AxDataSource } from './ax-data-source';
import { AxExportService } from './ax-export.service';
import { IconComponent } from '../../icon/icon.component';
import { AxComboboxComponent, AxComboboxOption } from '../../forms/ax-combobox.component';
import {
  AX_EMPTY_QUERY,
  AxAuditEvent,
  AxAuditEventType,
  AxBulkAction,
  AxColumnDef,
  AxDataTableConfig,
  AxExportFormat,
  AxExportScope,
  AxFilterDef,
  AxFilterOption,
  AxFilterValue,
  AxPage,
  AxQueryState,
  AxRowAction,
  AxSort,
  AxSortDirection,
} from './ax-data-table.types';

interface ColumnView<T> {
  readonly def: AxColumnDef<T>;
  visible: boolean;
  order: number;
}

@Component({
  selector: 'app-ax-data-table',
  standalone: true,
  imports: [CommonModule, FormsModule, AxDropdownDirective, AxDropdownItemDirective, IconComponent, AxComboboxComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-data-table.component.html',
  styleUrl: './ax-data-table.component.css',
})
export class AxDataTableComponent<T extends Record<string, unknown> = Record<string, unknown>>
  implements OnChanges, AfterContentInit, OnDestroy
{
  private readonly exporter = inject(AxExportService);
  private readonly destroyRef = inject(DestroyRef);

  @Input({ required: true }) config!: AxDataTableConfig<T>;
  @Input({ required: true }) dataSource!: AxDataSource<T>;

  @Output() rowClick = new EventEmitter<T>();
  @Output() rowAction = new EventEmitter<{ action: AxRowAction<T>; row: T }>();
  @Output() bulkAction = new EventEmitter<{ action: AxBulkAction<T>; selection: T[] }>();
  @Output() selectionChange = new EventEmitter<T[]>();

  @ContentChildren(AxCellDirective) cellTemplates!: QueryList<AxCellDirective>;
  @ContentChildren(AxRowExpandDirective) expandTemplates!: QueryList<AxRowExpandDirective>;

  // ── Reactive state ──────────────────────────────────────────────────────
  private readonly query$ = new BehaviorSubject<AxQueryState>(AX_EMPTY_QUERY);

  readonly page = signal<AxPage<T>>({ rows: [], total: 0, query: AX_EMPTY_QUERY });
  readonly loading = signal(false);
  readonly error = signal<unknown | null>(null);
  readonly query = signal<AxQueryState>(AX_EMPTY_QUERY);

  readonly selection = signal<T[]>([]);
  readonly expandedIds = signal<Set<string | number>>(new Set());
  readonly columnViews = signal<ColumnView<T>[]>([]);
  readonly columnManagerOpen = signal(false);
  readonly exporting = signal(false);

  /**
   * Searchable-combobox options per `select` filter key. Static `options`
   * seed immediately; `optionsLoader` filters (e.g. the Store list) merge in
   * when their fetch resolves. Previously the template only rendered static
   * `options`, so a loader-only filter (Store) showed an empty dropdown.
   */
  readonly filterOptions = signal<Record<string, AxComboboxOption[]>>({});

  /**
   * Per-`chips`-filter counts: filterKey → { optionValue(string) → count }.
   * Populated by each chips filter's `countsLoader`; absent until it resolves,
   * so chips render without a badge rather than a flash of "0".
   */
  readonly chipCounts = signal<Record<string, Record<string, number>>>({});

  private cellTplByKey = new Map<string, TemplateRef<{ $implicit: T; index: number }>>();
  private expandTpl: TemplateRef<{ $implicit: T }> | null = null;

  // Derived
  readonly visibleColumns = computed(() =>
    this.columnViews()
      .filter((c) => c.visible)
      .sort((a, b) => a.order - b.order)
      .map((c) => c.def),
  );

  readonly totalPages = computed(() => {
    const q = this.query();
    return q.pageSize > 0 ? Math.ceil(this.page().total / q.pageSize) : 1;
  });

  readonly hasSelection = computed(() => this.selection().length > 0);

  // ── Lifecycle ───────────────────────────────────────────────────────────

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['config'] && this.config) {
      this.initColumns();
      this.initFilterOptions();
      const initial: AxQueryState = {
        ...AX_EMPTY_QUERY,
        pageSize: this.config.pageSize ?? 20,
        filters: this.defaultFilters(),
      };
      this.query$.next(initial);
      // Seed the signal too so default-selected chips highlight on first paint.
      this.query.set(initial);
    }
    if (changes['dataSource'] && this.dataSource) {
      this.bindDataSource();
    }
  }

  ngAfterContentInit(): void {
    this.indexTemplates();
    this.cellTemplates.changes
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.indexTemplates());
  }

  ngOnDestroy(): void {
    this.dataSource?.disconnect();
  }

  private bindDataSource(): void {
    this.dataSource
      .connect(this.query$)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((page) => {
        this.page.set(page);
        this.query.set(page.query);
      });
    this.dataSource.state$
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe((s) => {
        this.loading.set(s.loading);
        this.error.set(s.error);
      });
  }

  private initColumns(): void {
    const views = this.config.columns.map((def, i) => ({
      def,
      visible: !def.hiddenByDefault,
      order: i,
    }));
    this.columnViews.set(views);
  }

  /**
   * Populate {@link filterOptions} for every `select` filter. Static option
   * lists map immediately; async `optionsLoader`s resolve and merge in (empty
   * on failure so the table stays usable).
   */
  private initFilterOptions(): void {
    const toOpt = (o: AxFilterOption): AxComboboxOption => ({ id: o.value as string | number, label: o.label });
    const seed: Record<string, AxComboboxOption[]> = {};
    for (const f of this.config.filters ?? []) {
      // select uses the combobox options; chips reuse the same option list.
      if ((f.type === 'select' || f.type === 'chips') && f.options) seed[f.key] = f.options.map(toOpt);
    }
    this.filterOptions.set(seed);
    this.chipCounts.set({});
    for (const f of this.config.filters ?? []) {
      if (f.type === 'select' && f.optionsLoader && !f.options) {
        f.optionsLoader()
          .then((opts) => this.filterOptions.update((m) => ({ ...m, [f.key]: opts.map(toOpt) })))
          .catch(() => this.filterOptions.update((m) => ({ ...m, [f.key]: [] })));
      }
      if (f.type === 'chips' && f.countsLoader) {
        f.countsLoader()
          .then((counts) => this.chipCounts.update((m) => ({ ...m, [f.key]: counts })))
          .catch(() => this.chipCounts.update((m) => ({ ...m, [f.key]: {} })));
      }
    }
  }

  /** The `chips`-type filters, rendered as a tab strip above the toolbar. */
  chipFilters(): readonly AxFilterDef[] {
    return (this.config.filters ?? []).filter((f) => f.type === 'chips');
  }

  private normChipValue(v: unknown): string {
    return v === undefined || v === null ? '' : String(v);
  }

  /** Whether `value` is the chip filter's current selection. */
  isChipActive(f: AxFilterDef, value: unknown): boolean {
    return this.normChipValue(this.query().filters[f.key]) === this.normChipValue(value);
  }

  /** Count badge for a chip option, or null while counts are still loading. */
  chipCount(key: string, value: unknown): number | null {
    const counts = this.chipCounts()[key];
    if (!counts) return null;
    return counts[this.normChipValue(value)] ?? 0;
  }

  private defaultFilters(): Record<string, AxFilterValue> {
    const out: Record<string, AxFilterValue> = {};
    for (const f of this.config.filters ?? []) {
      if (f.defaultValue !== undefined) out[f.key] = f.defaultValue;
    }
    return out;
  }

  private indexTemplates(): void {
    this.cellTplByKey = new Map();
    this.cellTemplates?.forEach((d) => this.cellTplByKey.set(d.key, d.tpl));
    this.expandTpl = this.expandTemplates?.first?.tpl ?? null;
  }

  // ── Query mutations ───────────────────────────────────────────────────────

  private patchQuery(patch: Partial<AxQueryState>, audit: AxAuditEventType): void {
    const next = { ...this.query$.value, ...patch };
    this.query$.next(next);
    // Reflect the pending query in the UI immediately (active chip, ngModels)
    // rather than waiting for the fetch round-trip; the data source re-confirms
    // it with the server-echoed query on the next emit.
    this.query.set(next);
    this.emitAudit(audit, patch as Record<string, unknown>);
  }

  onSearch(term: string): void {
    this.patchQuery({ search: term, pageIndex: 0 }, 'search');
  }

  onSort(col: AxColumnDef<T>, additive: boolean): void {
    if (!col.sortable) return;
    const key = col.key;
    const current = this.query$.value.sort;
    const existing = current.find((s) => s.key === key);

    let nextDir: AxSortDirection | null;
    if (!existing) nextDir = 'asc';
    else if (existing.direction === 'asc') nextDir = 'desc';
    else nextDir = null; // third click clears

    let nextSort: AxSort[];
    if (this.config.multiSort && additive) {
      nextSort = current.filter((s) => s.key !== key);
      if (nextDir) nextSort.push({ key, direction: nextDir });
    } else {
      nextSort = nextDir ? [{ key, direction: nextDir }] : [];
    }
    this.patchQuery({ sort: nextSort, pageIndex: 0 }, 'sort');
  }

  sortDirFor(key: string): AxSortDirection | null {
    return this.query().sort.find((s) => s.key === key)?.direction ?? null;
  }

  sortIndexFor(key: string): number {
    const i = this.query().sort.findIndex((s) => s.key === key);
    return i < 0 ? -1 : i;
  }

  onFilterChange(key: string, value: AxFilterValue): void {
    const filters = { ...this.query$.value.filters, [key]: value };
    this.patchQuery({ filters, pageIndex: 0 }, 'filter');
  }

  clearFilters(): void {
    this.patchQuery({ filters: this.defaultFilters(), search: '', pageIndex: 0 }, 'filter');
  }

  goToPage(index: number): void {
    if (index < 0 || index >= this.totalPages()) return;
    this.patchQuery({ pageIndex: index }, 'page');
  }

  prevPage(): void {
    this.goToPage(this.query().pageIndex - 1);
  }

  nextPage(): void {
    this.goToPage(this.query().pageIndex + 1);
  }

  setPageSize(size: number): void {
    this.patchQuery({ pageSize: size, pageIndex: 0 }, 'page');
  }

  retry(): void {
    // Re-emit current query; server source will re-fetch.
    this.query$.next({ ...this.query$.value });
    const ds = this.dataSource as { retry?: () => void };
    ds.retry?.();
  }

  // ── Selection ──────────────────────────────────────────────────────────

  rowIdOf(row: T): string | number {
    const id = this.config.rowId;
    return typeof id === 'function' ? id(row) : (row[id] as string | number);
  }

  isSelected(row: T): boolean {
    const id = this.rowIdOf(row);
    return this.selection().some((r) => this.rowIdOf(r) === id);
  }

  toggleRow(row: T, checked: boolean): void {
    const id = this.rowIdOf(row);
    const current = this.selection();
    const next = checked
      ? [...current.filter((r) => this.rowIdOf(r) !== id), row]
      : current.filter((r) => this.rowIdOf(r) !== id);
    this.selection.set(next);
    this.selectionChange.emit(next);
    this.emitAudit('select', { count: next.length });
  }

  allPageSelected(): boolean {
    const rows = this.page().rows;
    return rows.length > 0 && rows.every((r) => this.isSelected(r));
  }

  somePageSelected(): boolean {
    const rows = this.page().rows;
    const sel = rows.filter((r) => this.isSelected(r)).length;
    return sel > 0 && sel < rows.length;
  }

  toggleAllPage(checked: boolean): void {
    const rows = this.page().rows;
    let next: T[];
    if (checked) {
      const ids = new Set(this.selection().map((r) => this.rowIdOf(r)));
      next = [...this.selection(), ...rows.filter((r) => !ids.has(this.rowIdOf(r)))];
    } else {
      const pageIds = new Set(rows.map((r) => this.rowIdOf(r)));
      next = this.selection().filter((r) => !pageIds.has(this.rowIdOf(r)));
    }
    this.selection.set(next);
    this.selectionChange.emit(next);
  }

  clearSelection(): void {
    this.selection.set([]);
    this.selectionChange.emit([]);
  }

  // ── Expansion ────────────────────────────────────────────────────────────

  get hasExpandTemplate(): boolean {
    return this.expandTpl !== null;
  }

  expandTemplate(): TemplateRef<{ $implicit: T }> | null {
    return this.expandTpl;
  }

  isExpanded(row: T): boolean {
    return this.expandedIds().has(this.rowIdOf(row));
  }

  toggleExpand(row: T): void {
    const id = this.rowIdOf(row);
    const next = new Set(this.expandedIds());
    next.has(id) ? next.delete(id) : next.add(id);
    this.expandedIds.set(next);
  }

  // ── Cell rendering ────────────────────────────────────────────────────────

  cellTemplateFor(key: string): TemplateRef<{ $implicit: T; index: number }> | null {
    return this.cellTplByKey.get(key) ?? null;
  }

  renderCell(row: T, col: AxColumnDef<T>): string {
    const raw = col.value ? col.value(row) : row[col.key];
    if (col.format) return col.format(raw, row);
    return raw == null ? '' : String(raw);
  }

  // ── Row actions ────────────────────────────────────────────────────────

  visibleRowActions(row: T): AxRowAction<T>[] {
    return (this.config.rowActions ?? []).filter(
      (a) => !(a.hidden?.(row) ?? false) && (a.can?.(row) ?? true),
    );
  }

  /** Actions shown inline as icon buttons (first N of the visible set). */
  inlineRowActions(row: T): AxRowAction<T>[] {
    const max = this.config.maxInlineActions ?? 2;
    const visible = this.visibleRowActions(row);
    // If only one would overflow, show it inline rather than a 1-item menu.
    if (visible.length <= max + 1) return visible;
    return visible.slice(0, max);
  }

  /** Actions collapsed into the "⋯" overflow menu (remainder). */
  overflowRowActions(row: T): AxRowAction<T>[] {
    const max = this.config.maxInlineActions ?? 2;
    const visible = this.visibleRowActions(row);
    if (visible.length <= max + 1) return [];
    return visible.slice(max);
  }

  onRowAction(action: AxRowAction<T>, row: T, ev: Event): void {
    ev.stopPropagation();
    if (action.disabled?.(row)) return;
    this.rowAction.emit({ action, row });
    this.emitAudit('row-action', { action: action.id });
  }

  availableBulkActions(): AxBulkAction<T>[] {
    const sel = this.selection();
    return (this.config.bulkActions ?? []).filter((a) => a.can?.(sel) ?? true);
  }

  onBulkAction(action: AxBulkAction<T>): void {
    this.bulkAction.emit({ action, selection: this.selection() });
    this.emitAudit('bulk-action', { action: action.id, count: this.selection().length });
  }

  // ── Column management ────────────────────────────────────────────────────

  toggleColumnManager(): void {
    this.columnManagerOpen.update((v) => !v);
  }

  toggleColumnVisible(key: string): void {
    this.columnViews.update((views) =>
      views.map((v) => (v.def.key === key && !v.def.pinned ? { ...v, visible: !v.visible } : v)),
    );
    this.emitAudit('column-visibility', { key });
  }

  moveColumn(key: string, dir: -1 | 1): void {
    this.columnViews.update((views) => {
      const sorted = [...views].sort((a, b) => a.order - b.order);
      const idx = sorted.findIndex((v) => v.def.key === key);
      const swap = idx + dir;
      if (idx < 0 || swap < 0 || swap >= sorted.length) return views;
      const a = sorted[idx];
      const b = sorted[swap];
      const ao = a.order;
      a.order = b.order;
      b.order = ao;
      return [...views];
    });
  }

  // ── Export ────────────────────────────────────────────────────────────────

  get exportFormats(): readonly AxExportFormat[] {
    return this.config.export?.formats ?? ['csv', 'xlsx', 'pdf'];
  }

  async doExport(format: AxExportFormat, scope: AxExportScope): Promise<void> {
    this.exporting.set(true);
    this.emitAudit('export', { format, scope });
    try {
      let rows: readonly T[];
      if (scope === 'selected') rows = this.selection();
      else if (scope === 'page') rows = this.page().rows;
      else rows = await this.dataSource.fetchAllForExport(this.query$.value);

      const filename =
        this.config.export?.filename ??
        `${this.config.tableId}-${new Date().toISOString().slice(0, 10)}`;
      await this.exporter.export(format, rows, this.visibleColumns(), filename);
    } catch (err) {
      this.emitAudit('error', { phase: 'export', message: String(err) });
    } finally {
      this.exporting.set(false);
    }
  }

  // ── Pagination display ────────────────────────────────────────────────────

  pageStart(): number {
    const q = this.query();
    return this.page().total === 0 ? 0 : q.pageIndex * q.pageSize + 1;
  }

  pageEnd(): number {
    const q = this.query();
    return Math.min((q.pageIndex + 1) * q.pageSize, this.page().total);
  }

  pageSizeOptions(): readonly number[] {
    return this.config.pageSizeOptions ?? [20, 50, 100];
  }

  // ── Audit ─────────────────────────────────────────────────────────────────

  private emitAudit(type: AxAuditEventType, detail?: Record<string, unknown>): void {
    const ev: AxAuditEvent = { type, at: Date.now(), detail };
    this.config.onAudit?.(ev);
  }

  // ── trackBy ─────────────────────────────────────────────────────────────

  trackRow = (_: number, row: T): string | number => this.rowIdOf(row);
  trackColKey = (_: number, col: AxColumnDef<T>): string => col.key;
  trackColView = (_: number, cv: ColumnView<T>): string => cv.def.key;
}
