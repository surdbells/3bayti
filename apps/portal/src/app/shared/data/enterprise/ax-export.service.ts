/**
 * Export framework for the enterprise table.
 *
 * Produces CSV, XLSX, and PDF from a set of rows + the *currently visible*
 * column definitions, honouring each column's `format` and `excludeFromExport`
 * flags. The table component decides the row set based on scope (page /
 * selected / all) and hands it here.
 *
 * Heavy libraries (xlsx, jspdf) are dynamically imported so they never enter
 * the initial bundle, they only load the first time a user actually exports.
 */

import { Injectable } from '@angular/core';
import {
  AxColumnDef,
  AxExportFormat,
} from './ax-data-table.types';

export interface AxExportColumn<T> {
  readonly header: string;
  readonly accessor: (row: T) => string;
}

@Injectable({ providedIn: 'root' })
export class AxExportService {
  /**
   * Build export columns from visible column defs, dropping any flagged
   * `excludeFromExport`. The accessor reuses the column's value/format so
   * the export matches what the user sees.
   */
  buildColumns<T>(columns: readonly AxColumnDef<T>[]): AxExportColumn<T>[] {
    return columns
      .filter((c) => !c.excludeFromExport)
      .map((c) => ({
        header: c.label,
        accessor: (row: T): string => {
          const raw = c.value ? c.value(row) : (row as Record<string, unknown>)[c.key];
          if (c.format) return c.format(raw, row);
          if (raw == null) return '';
          return String(raw);
        },
      }));
  }

  async export<T>(
    format: AxExportFormat,
    rows: readonly T[],
    columns: readonly AxColumnDef<T>[],
    filename: string,
  ): Promise<void> {
    const cols = this.buildColumns(columns);
    switch (format) {
      case 'csv':
        return this.exportCsv(rows, cols, filename);
      case 'xlsx':
        return this.exportXlsx(rows, cols, filename);
      case 'pdf':
        return this.exportPdf(rows, cols, filename);
    }
  }

  // ── CSV ────────────────────────────────────────────────────────────────

  private async exportCsv<T>(
    rows: readonly T[],
    cols: readonly AxExportColumn<T>[],
    filename: string,
  ): Promise<void> {
    const esc = (v: string): string => {
      // RFC-4180: wrap in quotes if it contains comma, quote, or newline.
      if (/[",\n\r]/.test(v)) return `"${v.replace(/"/g, '""')}"`;
      return v;
    };
    const header = cols.map((c) => esc(c.header)).join(',');
    const body = rows
      .map((row) => cols.map((c) => esc(c.accessor(row))).join(','))
      .join('\r\n');
    // Prepend BOM so Excel opens UTF-8 correctly.
    const csv = '\uFEFF' + header + '\r\n' + body;
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    await this.save(blob, `${filename}.csv`);
  }

  // ── XLSX ───────────────────────────────────────────────────────────────

  private async exportXlsx<T>(
    rows: readonly T[],
    cols: readonly AxExportColumn<T>[],
    filename: string,
  ): Promise<void> {
    const XLSX = await import('xlsx');
    const aoa: string[][] = [
      cols.map((c) => c.header),
      ...rows.map((row) => cols.map((c) => c.accessor(row))),
    ];
    const ws = XLSX.utils.aoa_to_sheet(aoa);
    // Reasonable column widths from header + sampled content.
    ws['!cols'] = cols.map((c, i) => {
      let max = c.header.length;
      for (let r = 0; r < Math.min(rows.length, 200); r++) {
        max = Math.max(max, (aoa[r + 1]?.[i] ?? '').length);
      }
      return { wch: Math.min(Math.max(max + 2, 8), 60) };
    });
    const wb = XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, ws, 'Export');
    const out = XLSX.write(wb, { bookType: 'xlsx', type: 'array' });
    const blob = new Blob([out], {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    await this.save(blob, `${filename}.xlsx`);
  }

  // ── PDF ────────────────────────────────────────────────────────────────

  private async exportPdf<T>(
    rows: readonly T[],
    cols: readonly AxExportColumn<T>[],
    filename: string,
  ): Promise<void> {
    const { default: jsPDF } = await import('jspdf');
    const autoTable = (await import('jspdf-autotable')).default;
    // Landscape for wide tables.
    const doc = new jsPDF({ orientation: cols.length > 5 ? 'landscape' : 'portrait' });
    autoTable(doc, {
      head: [cols.map((c) => c.header)],
      body: rows.map((row) => cols.map((c) => c.accessor(row))),
      styles: { fontSize: 8, cellPadding: 2 },
      headStyles: { fillColor: [37, 71, 90] }, // ax slate
      margin: { top: 16, left: 10, right: 10 },
    });
    doc.save(`${filename}.pdf`);
  }

  // ── Save helper (file-saver, dynamically imported) ──────────────────────

  private async save(blob: Blob, filename: string): Promise<void> {
    const { saveAs } = await import('file-saver');
    saveAs(blob, filename);
  }
}
