/**
 * Data source abstraction for the enterprise table — the API abstraction
 * layer required by the brief.
 *
 * A data source maps an `AxQueryState` stream to an `AxPage<T>` stream. The
 * table component owns a query subject; the data source decides how to turn
 * each query into a page of rows.
 *
 *   query$  ──▶  AxDataSource.connect()  ──▶  AxPage<T>$
 *
 * Two built-in implementations:
 *
 * - `AxClientDataSource`  — holds the full array in memory and performs
 *   search / multi-sort / pagination client-side. Suitable for small,
 *   already-loaded sets.
 *
 * - `AxServerDataSource`  — delegates each query to a `fetcher` callback
 *   (typically a PortalCrudAdapter call). Debounces rapid input, cancels
 *   superseded requests via `switchMap`, and surfaces loading + error
 *   state. Suitable for the 100k-record requirement.
 *
 * Both are framework-agnostic (no Angular DI) so they're trivially unit
 * testable and reusable.
 */

import {
  BehaviorSubject,
  Observable,
  Subject,
  combineLatest,
  of,
} from 'rxjs';
import {
  catchError,
  debounceTime,
  distinctUntilChanged,
  map,
  scan,
  shareReplay,
  startWith,
  switchMap,
} from 'rxjs/operators';
import {
  AxColumnDef,
  AxPage,
  AxQueryState,
  AxSort,
} from './ax-data-table.types';

export interface AxDataSourceState {
  readonly loading: boolean;
  readonly error: unknown | null;
}

export abstract class AxDataSource<T> {
  /** Connect a query stream and receive pages. */
  abstract connect(query$: Observable<AxQueryState>): Observable<AxPage<T>>;
  /** Loading / error state for the UI. */
  abstract readonly state$: Observable<AxDataSourceState>;
  /**
   * Fetch the full filtered/sorted result set for export (scope = 'all'),
   * bypassing pagination. Server sources issue a wide query; client sources
   * return the in-memory filtered array.
   */
  abstract fetchAllForExport(query: AxQueryState): Promise<readonly T[]>;
  /** Optional teardown. */
  disconnect(): void {}
}

// ─────────────────────────────────────────────────────────────────────────
// Shared client-side query helpers (also used by ClientDataSource)
// ─────────────────────────────────────────────────────────────────────────

function accessValue<T>(row: T, col: AxColumnDef<T> | undefined, key: string): unknown {
  if (col?.value) return col.value(row);
  return (row as Record<string, unknown>)[key];
}

function compare(a: unknown, b: unknown): number {
  if (a == null && b == null) return 0;
  if (a == null) return -1;
  if (b == null) return 1;
  if (typeof a === 'number' && typeof b === 'number') return a - b;
  // Date-ish strings and plain strings both sort lexically after coercion;
  // numeric strings are compared numerically when both parse cleanly.
  const na = Number(a);
  const nb = Number(b);
  if (!Number.isNaN(na) && !Number.isNaN(nb) && String(a).trim() !== '' && String(b).trim() !== '') {
    return na - nb;
  }
  return String(a).localeCompare(String(b));
}

export function axApplyMultiSort<T>(
  rows: readonly T[],
  sort: readonly AxSort[],
  columns: readonly AxColumnDef<T>[],
): T[] {
  if (sort.length === 0) return [...rows];
  const colByKey = new Map(columns.map((c) => [c.key, c]));
  const out = [...rows];
  out.sort((ra, rb) => {
    for (const s of sort) {
      const col = colByKey.get(s.key);
      const va = accessValue(ra, col, s.key);
      const vb = accessValue(rb, col, s.key);
      const cmp = compare(va, vb);
      if (cmp !== 0) return s.direction === 'asc' ? cmp : -cmp;
    }
    return 0;
  });
  return out;
}

export function axApplyGlobalSearch<T>(
  rows: readonly T[],
  search: string,
  columns: readonly AxColumnDef<T>[],
): T[] {
  const term = search.trim().toLowerCase();
  if (term === '') return [...rows];
  return rows.filter((row) =>
    columns.some((col) => {
      const raw = accessValue(row, col, col.key);
      if (raw == null) return false;
      const text = col.format ? col.format(raw, row) : String(raw);
      return text.toLowerCase().includes(term);
    }),
  );
}

// ─────────────────────────────────────────────────────────────────────────
// Client data source
// ─────────────────────────────────────────────────────────────────────────

export class AxClientDataSource<T> extends AxDataSource<T> {
  private readonly data$ = new BehaviorSubject<readonly T[]>([]);
  private readonly _state$ = new BehaviorSubject<AxDataSourceState>({ loading: false, error: null });
  readonly state$ = this._state$.asObservable();

  constructor(
    private readonly columns: readonly AxColumnDef<T>[],
    initial: readonly T[] = [],
  ) {
    super();
    this.data$.next(initial);
  }

  /** Replace the backing data (e.g. after an initial fetch). */
  setData(rows: readonly T[]): void {
    this.data$.next(rows);
  }

  private filterAndSort(rows: readonly T[], q: AxQueryState): T[] {
    let out = axApplyGlobalSearch(rows, q.search, this.columns);
    out = axApplyMultiSort(out, q.sort, this.columns);
    return out;
  }

  connect(query$: Observable<AxQueryState>): Observable<AxPage<T>> {
    return combineLatest([this.data$, query$]).pipe(
      map(([rows, q]) => {
        const filtered = this.filterAndSort(rows, q);
        const start = q.pageIndex * q.pageSize;
        const pageRows = q.pageSize > 0 ? filtered.slice(start, start + q.pageSize) : filtered;
        return { rows: pageRows, total: filtered.length, query: q } satisfies AxPage<T>;
      }),
      shareReplay({ bufferSize: 1, refCount: true }),
    );
  }

  async fetchAllForExport(query: AxQueryState): Promise<readonly T[]> {
    return this.filterAndSort(this.data$.value, query);
  }
}

// ─────────────────────────────────────────────────────────────────────────
// Server data source
// ─────────────────────────────────────────────────────────────────────────

export interface AxServerFetchResult<T> {
  readonly rows: readonly T[];
  readonly total: number;
}

export type AxServerFetcher<T> = (
  query: AxQueryState,
  opts: { readonly forExport?: boolean },
) => Observable<AxServerFetchResult<T>>;

export class AxServerDataSource<T> extends AxDataSource<T> {
  private readonly _state$ = new BehaviorSubject<AxDataSourceState>({ loading: false, error: null });
  readonly state$ = this._state$.asObservable();

  private readonly retry$ = new Subject<void>();

  constructor(
    private readonly fetcher: AxServerFetcher<T>,
    private readonly debounceMs = 250,
  ) {
    super();
  }

  /** Trigger a re-fetch of the current query (used by the retry button). */
  retry(): void {
    this.retry$.next();
  }

  connect(query$: Observable<AxQueryState>): Observable<AxPage<T>> {
    // Monotonic tick that increments on every retry() call. Carrying it through
    // the pipeline lets a retry force a re-fetch even when the table's own
    // AxQueryState (q) is unchanged — which is the case for pages that drive
    // filtering through EXTERNAL controls (e.g. admin/customers, admin/stores)
    // and call retry() to reload. Without it, distinctUntilChanged sees the
    // same q and dedupes the retry away, so the grid never refreshes.
    const retryTick$ = this.retry$.pipe(scan((n) => n + 1, 0), startWith(0));

    return combineLatest([query$, retryTick$]).pipe(
      // Debounce only search/filter churn; the table already coalesces
      // page/sort clicks, but debounce here protects the network layer.
      debounceTime(this.debounceMs),
      distinctUntilChanged(
        ([qa, ta], [qb, tb]) => ta === tb && JSON.stringify(qa) === JSON.stringify(qb),
      ),
      map(([q]) => q),
      switchMap((q) => {
        this._state$.next({ loading: true, error: null });
        return this.fetcher(q, {}).pipe(
          map(
            (res) =>
              ({ rows: res.rows, total: res.total, query: q }) satisfies AxPage<T>,
          ),
          // success clears loading
          map((page) => {
            this._state$.next({ loading: false, error: null });
            return page;
          }),
          catchError((err) => {
            this._state$.next({ loading: false, error: err });
            // Emit an empty page so the grid renders an error/empty state
            // rather than hanging on the previous data.
            return of({ rows: [], total: 0, query: q } satisfies AxPage<T>);
          }),
        );
      }),
      shareReplay({ bufferSize: 1, refCount: true }),
    );
  }

  async fetchAllForExport(query: AxQueryState): Promise<readonly T[]> {
    // Pull a wide page (server caps via its own MAX_LIMIT). Callers that
    // need true full-dataset export should paginate in the fetcher.
    const exportQuery: AxQueryState = { ...query, pageIndex: 0, pageSize: 100000 };
    const res = await new Promise<AxServerFetchResult<T>>((resolve, reject) => {
      this.fetcher(exportQuery, { forExport: true }).subscribe({
        next: resolve,
        error: reject,
      });
    });
    return res.rows;
  }
}
