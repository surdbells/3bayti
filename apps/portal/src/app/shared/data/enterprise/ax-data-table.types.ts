/**
 * Enterprise data-table type system.
 *
 * Config-driven, fully generic over the row type `T`. These types are the
 * single contract shared by the table component, the data sources, the
 * filter bar, and the export service. Everything else composes around them.
 *
 * Design notes
 * ------------
 * - Column definitions are declarative and serialisable (except render
 *   functions), so column visibility/order/state can be persisted.
 * - Sorting is multi-column: an ordered list of {key, direction}. Single
 *   sort is just a list of length 1.
 * - The query state (`AxQueryState`) is the canonical description of "what
 *   the user is currently looking at": page, sort, search, filters. Both
 *   client and server data sources consume it and produce an `AxPage<T>`.
 * - Permission gating is expressed as a predicate so callers can wire it to
 *   their own session/role model without the table knowing the details.
 */

// ─────────────────────────────────────────────────────────────────────────
// Sorting
// ─────────────────────────────────────────────────────────────────────────

export type AxSortDirection = 'asc' | 'desc';

export interface AxSort {
  /** Column key (matches AxColumnDef.key). */
  readonly key: string;
  readonly direction: AxSortDirection;
}

// ─────────────────────────────────────────────────────────────────────────
// Filtering
// ─────────────────────────────────────────────────────────────────────────

export type AxFilterType =
  | 'text'
  | 'select'
  | 'multiselect'
  | 'date-range'
  | 'boolean'
  | 'number-range';

export interface AxFilterOption {
  readonly label: string;
  readonly value: string | number | boolean;
}

export interface AxFilterDef {
  /** Stable key; becomes the property name in AxQueryState.filters. */
  readonly key: string;
  readonly label: string;
  readonly type: AxFilterType;
  /** For select / multiselect. May be provided lazily via `optionsLoader`. */
  readonly options?: readonly AxFilterOption[];
  /** Async option provider (e.g. fetch vendor list). Overrides `options`. */
  readonly optionsLoader?: () => Promise<readonly AxFilterOption[]>;
  /** Placeholder / empty-state text for the control. */
  readonly placeholder?: string;
  /** Default value applied on first render. */
  readonly defaultValue?: AxFilterValue;
}

export type AxDateRange = { readonly from: string | null; readonly to: string | null };
export type AxNumberRange = { readonly min: number | null; readonly max: number | null };

export type AxFilterValue =
  | string
  | number
  | boolean
  | null
  | readonly (string | number)[]
  | AxDateRange
  | AxNumberRange;

export type AxFilterValues = Readonly<Record<string, AxFilterValue>>;

// ─────────────────────────────────────────────────────────────────────────
// Query state — the canonical "what am I looking at" description
// ─────────────────────────────────────────────────────────────────────────

export interface AxQueryState {
  /** 0-indexed page. */
  readonly pageIndex: number;
  readonly pageSize: number;
  /** Ordered multi-sort. Empty = no explicit sort. */
  readonly sort: readonly AxSort[];
  /** Global search term (debounced upstream). */
  readonly search: string;
  /** Per-filter values keyed by AxFilterDef.key. */
  readonly filters: AxFilterValues;
}

export const AX_EMPTY_QUERY: AxQueryState = {
  pageIndex: 0,
  pageSize: 20,
  sort: [],
  search: '',
  filters: {},
};

// ─────────────────────────────────────────────────────────────────────────
// Page result
// ─────────────────────────────────────────────────────────────────────────

export interface AxPage<T> {
  readonly rows: readonly T[];
  readonly total: number;
  /** Echo of the query that produced this page (for race-safety checks). */
  readonly query: AxQueryState;
}

// ─────────────────────────────────────────────────────────────────────────
// Columns
// ─────────────────────────────────────────────────────────────────────────

export type AxColumnAlign = 'left' | 'center' | 'right';

export interface AxColumnDef<T = unknown> {
  /** Stable identity. Used for sort keys, visibility, ordering, export. */
  readonly key: string;
  readonly label: string;
  /** Server-side sort key if it differs from `key`. */
  readonly sortKey?: string;
  readonly sortable?: boolean;
  readonly align?: AxColumnAlign;
  /** CSS width, e.g. '12rem', '120px'. */
  readonly width?: string;
  /** CSS width applied only below the responsive breakpoint (≤768px). Lets a
   *  wide sticky column (e.g. a name) stay narrow on phones so neighbouring
   *  columns aren't pushed off-screen. Falls back to `width` when unset. */
  readonly widthMobile?: string;
  /** Hide below the responsive breakpoint. */
  readonly hideOnMobile?: boolean;
  /** Sticky to the left or right edge during horizontal scroll. */
  readonly sticky?: 'left' | 'right';
  /** Hidden by default (user can re-enable via column manager). */
  readonly hiddenByDefault?: boolean;
  /** Exclude entirely from the column-visibility manager (always shown). */
  readonly pinned?: boolean;
  /**
   * Plain-value accessor for default rendering, sorting (client mode) and
   * export. If omitted, `row[key]` is used.
   */
  readonly value?: (row: T) => unknown;
  /**
   * Display formatter for the default cell renderer and exports. Receives
   * the accessed value. For rich cells, use a cell template in the host
   * instead (see AxCellDirective).
   */
  readonly format?: (value: unknown, row: T) => string;
  /** Exclude this column from exports even when visible. */
  readonly excludeFromExport?: boolean;
}

// ─────────────────────────────────────────────────────────────────────────
// Row actions & bulk actions
// ─────────────────────────────────────────────────────────────────────────

export interface AxRowAction<T = unknown> {
  readonly id: string;
  readonly label: string;
  /** Material symbol name. */
  readonly icon?: string;
  /** Visual treatment. */
  readonly variant?: 'default' | 'danger';
  /** Hide the action for a given row. */
  readonly hidden?: (row: T) => boolean;
  /** Disable (but show) the action for a given row. */
  readonly disabled?: (row: T) => boolean;
  /** Permission predicate; falsey hides the action. */
  readonly can?: (row: T) => boolean;
}

export interface AxBulkAction<T = unknown> {
  readonly id: string;
  readonly label: string;
  readonly icon?: string;
  readonly variant?: 'default' | 'danger';
  /** Permission predicate evaluated against the current selection. */
  readonly can?: (selection: readonly T[]) => boolean;
}

// ─────────────────────────────────────────────────────────────────────────
// Export
// ─────────────────────────────────────────────────────────────────────────

export type AxExportFormat = 'csv' | 'xlsx' | 'pdf';

export type AxExportScope = 'all' | 'selected' | 'page';

export interface AxExportRequest {
  readonly format: AxExportFormat;
  readonly scope: AxExportScope;
  /** Base filename without extension. */
  readonly filename?: string;
}

// ─────────────────────────────────────────────────────────────────────────
// Audit hooks
// ─────────────────────────────────────────────────────────────────────────

export type AxAuditEventType =
  | 'query'
  | 'sort'
  | 'filter'
  | 'search'
  | 'page'
  | 'select'
  | 'row-action'
  | 'bulk-action'
  | 'export'
  | 'column-visibility'
  | 'error';

export interface AxAuditEvent {
  readonly type: AxAuditEventType;
  readonly at: number;
  readonly detail?: Record<string, unknown>;
}

// ─────────────────────────────────────────────────────────────────────────
// Top-level table config
// ─────────────────────────────────────────────────────────────────────────

export interface AxDataTableConfig<T = unknown> {
  /** Stable id for persisting column prefs (visibility/order) in memory. */
  readonly tableId: string;
  readonly columns: readonly AxColumnDef<T>[];
  /** Property used as the stable row identity for trackBy + selection. */
  readonly rowId: keyof T | ((row: T) => string | number);
  /** 'server' uses an AxDataSource; 'client' sorts/paginates in-memory. */
  readonly mode: 'server' | 'client';
  readonly pageSize?: number;
  readonly pageSizeOptions?: readonly number[];
  /** Multi-column sort enabled (shift-click to add). */
  readonly multiSort?: boolean;
  readonly selectable?: boolean;
  readonly expandable?: boolean;
  readonly globalSearch?: boolean;
  readonly searchPlaceholder?: string;
  readonly filters?: readonly AxFilterDef[];
  readonly rowActions?: readonly AxRowAction<T>[];
  /**
   * How many row actions to show inline before collapsing the rest into a
   * "⋯" overflow menu. Default 2. Keeps rows uncluttered when a screen has
   * many per-row actions. Set to a high number to force all inline.
   */
  readonly maxInlineActions?: number;
  readonly bulkActions?: readonly AxBulkAction<T>[];
  readonly export?: {
    readonly enabled: boolean;
    readonly formats?: readonly AxExportFormat[];
    readonly filename?: string;
  };
  /** Enable CDK virtual scrolling (for very large client-mode datasets). */
  readonly virtualScroll?: boolean;
  readonly virtualItemSize?: number;
  /** Visual density. */
  readonly compact?: boolean;
  readonly hover?: boolean;
  readonly stickyHeader?: boolean;
  /** Empty/loading copy. */
  readonly emptyTitle?: string;
  readonly emptyDescription?: string;
  /** Optional audit sink. */
  readonly onAudit?: (event: AxAuditEvent) => void;
}
