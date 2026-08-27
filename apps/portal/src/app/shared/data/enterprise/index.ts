/**
 * Enterprise data table, public API.
 *
 *   import {
 *     AxDataTableComponent, AxCellDirective, AxRowExpandDirective,
 *     AxClientDataSource, AxServerDataSource, AxExportService,
 *     type AxDataTableConfig, type AxColumnDef, …
 *   } from '@shared/data/enterprise';
 */

export { AxDataTableComponent } from './ax-data-table.component';
export { AxCellDirective, AxRowExpandDirective } from './ax-cell.directive';
export { AxExportService } from './ax-export.service';
export type { AxExportColumn } from './ax-export.service';

export {
  AxDataSource,
  AxClientDataSource,
  AxServerDataSource,
  axApplyMultiSort,
  axApplyGlobalSearch,
} from './ax-data-source';
export type {
  AxDataSourceState,
  AxServerFetcher,
  AxServerFetchResult,
} from './ax-data-source';

export { AX_EMPTY_QUERY } from './ax-data-table.types';
export type {
  AxSort,
  AxSortDirection,
  AxFilterType,
  AxFilterOption,
  AxFilterDef,
  AxFilterValue,
  AxFilterValues,
  AxDateRange,
  AxNumberRange,
  AxQueryState,
  AxPage,
  AxColumnAlign,
  AxColumnDef,
  AxRowAction,
  AxBulkAction,
  AxExportFormat,
  AxExportScope,
  AxExportRequest,
  AxAuditEvent,
  AxAuditEventType,
  AxDataTableConfig,
} from './ax-data-table.types';
