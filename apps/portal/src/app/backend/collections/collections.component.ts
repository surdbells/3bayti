import { Component, ElementRef, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { CdkDragDrop, DragDropModule, moveItemInArray } from '@angular/cdk/drag-drop';
import { Subscription } from 'rxjs';

import { NavigationHistoryService } from '../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { PermissionService } from '../../services/permission.service';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxConfirmService } from '../../shared/overlays/ax-confirm.service';
import { AxDropdownDirective, AxDropdownItemDirective } from '../../shared/overlays/ax-dropdown.directive';
import { AxCanDirective } from '../../shared/security/ax-can.directive';
import { AxExportService, type AxColumnDef } from '../../shared/data/enterprise';
import { CollectionRow, collectionRowsFromApi, fetchAllCollections } from './collection-list.api';

export type { CollectionRow } from './collection-list.api';

/** Which control of a row keeps keyboard focus after a move. */
type MoveFocus = 'handle' | 'up' | 'down';

/**
 * Admin "Collections" list — admin-curated storefront collections, shown in
 * their storefront order (display_order). Admins with
 * catalog.collections_manage reorder them by drag and drop (or the keyboard:
 * arrow keys on a row's handle, or its Move up / Move down buttons); every
 * reorder is saved at once through PUT /admin/collections/order with the full
 * id order, optimistically, and rolled back if the save fails. That order IS
 * the storefront "Shop by collection" order. View-only admins get a
 * read-only list.
 *
 * Each row opens the edit page (fields + product curation) by its v3 id;
 * delete removes the collection and its curated product list (the products
 * themselves stay).
 */
@Component({
  selector: 'app-collections',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    RouterLink,
    DragDropModule,
    IconComponent,
    AxCanDirective,
    AxDropdownDirective,
    AxDropdownItemDirective,
  ],
  templateUrl: './collections.component.html',
  styleUrl: './collections.component.css',
})
export class CollectionsComponent implements OnInit, OnDestroy {
  /** Collections in storefront order. */
  rows: CollectionRow[] = [];
  loading = true;
  loadError = '';
  /**
   * A reorder is being saved. Moves made meanwhile still apply at once and
   * are saved together (with the full order) as soon as this save lands.
   */
  saving = false;
  /** The order the server last confirmed: what a failed save rolls back to. */
  private confirmed: CollectionRow[] = [];
  /** Moves were made while a save was in flight: save again when it lands. */
  private resaveQueued = false;
  /** The latest moved row and the control that moved it (focus follows it). */
  private lastMove: { id: number; name: string; focus: MoveFocus | null } | null = null;
  /** Client-side name/slug filter. Reordering is paused while it is set. */
  search = '';
  /** Polite screen-reader announcement (moves, saves, rollbacks). */
  announcement = '';
  /** Image URLs that failed to load (the row shows the placeholder). */
  readonly brokenImages = new Set<string>();
  readonly skeletonRows = Array.from({ length: 6 }, (_, i) => i);

  private readonly subs = new Subscription();

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private confirm: AxConfirmService,
    private perms: PermissionService,
    private exporter: AxExportService,
    private host: ElementRef<HTMLElement>,
  ) {}

  ngOnInit() {
    this.load();
  }

  ngOnDestroy() {
    this.subs.unsubscribe();
  }

  /** Reorder, edit and delete need catalog.collections_manage. */
  get canManage(): boolean {
    return this.perms.can('catalog.collections_manage');
  }

  /** Rows matching the search box (all rows when it's empty). */
  get visibleRows(): CollectionRow[] {
    const q = this.search.trim().toLowerCase();
    if (!q) return this.rows;
    return this.rows.filter((r) => r.name.toLowerCase().includes(q) || r.slug.toLowerCase().includes(q));
  }

  get filtering(): boolean {
    return this.search.trim().length > 0;
  }

  /** Drag handles and move buttons work (manage permission, full list, idle). */
  get canReorder(): boolean {
    return this.canManage && !this.filtering && !this.loading && !this.loadError && this.rows.length > 1;
  }

  get activeCount(): number {
    return this.rows.filter((r) => r.is_active).length;
  }

  // ── load ─────────────────────────────────────────────────────────
  /**
   * Load EVERY collection (paging with limit 100) in storefront order.
   * `silent` keeps the current list on screen while refreshing.
   */
  load(silent = false) {
    if (!silent) {
      this.loading = true;
      this.loadError = '';
    }
    this.subs.add(
      fetchAllCollections(this.adapter).subscribe({
        next: (rows) => {
          this.loading = false;
          this.loadError = '';
          // A refresh that lands mid-save would undo moves not saved yet; the
          // save's own response brings the server's order instead.
          if (this.saving) return;
          this.rows = rows;
          this.confirmed = rows;
        },
        error: (err: any) => {
          this.loading = false;
          const msg = apiErrorMessage(err, 'Unable to load collections at this time.');
          if (silent) {
            this.toast.error(msg);
          } else {
            this.rows = [];
            this.loadError = msg;
          }
        },
      }),
    );
  }

  // ── reorder ──────────────────────────────────────────────────────
  onDrop(event: CdkDragDrop<CollectionRow[]>) {
    if (!this.canReorder || event.previousIndex === event.currentIndex) return;
    this.moveTo(event.previousIndex, event.currentIndex, null);
  }

  /** Move a row up; focus stays on the control that did it (the handle's arrow keys, or this button). */
  moveUp(index: number, focus: MoveFocus = 'up') {
    if (index > 0) this.moveTo(index, index - 1, focus);
  }

  moveDown(index: number, focus: MoveFocus = 'down') {
    if (index < this.rows.length - 1) this.moveTo(index, index + 1, focus);
  }

  /** Arrow keys on a row's drag handle move it (the keyboard alternative). */
  onHandleKeydown(event: KeyboardEvent, index: number) {
    if (event.key === 'ArrowUp' || event.key === 'ArrowDown') {
      event.preventDefault();
      if (event.key === 'ArrowUp') this.moveUp(index, 'handle');
      else this.moveDown(index, 'handle');
    }
  }

  /**
   * Move a row and save the FULL new order (contract B). Optimistic: the list
   * changes at once. A move made while a save is in flight is applied too and
   * saved (again with the full order) as soon as that save lands, so quick
   * arrow-key presses are never dropped. On failure the list snaps back to
   * the last order the server confirmed and the API's message is shown.
   */
  private moveTo(from: number, to: number, focus: MoveFocus | null) {
    if (!this.canReorder) return;
    if (from === to || from < 0 || to < 0 || from >= this.rows.length || to >= this.rows.length) return;
    const next = [...this.rows];
    moveItemInArray(next, from, to);
    const moved = next[to];
    this.rows = next;
    this.lastMove = { id: moved.id, name: moved.name, focus };
    if (focus) this.refocus(moved.id, focus);
    if (this.saving) {
      this.resaveQueued = true;
      this.announce(`Moved ${moved.name} to position ${to + 1} of ${next.length}. It will be saved when the current save finishes.`);
      return;
    }
    this.announce(`Moved ${moved.name} to position ${to + 1} of ${next.length}. Saving…`);
    this.saveOrder();
  }

  /** PUT the current full order; chains another save if moves were queued meanwhile. */
  private saveOrder() {
    this.saving = true;
    this.resaveQueued = false;
    const sent = this.rows;
    this.adapter.put_v3('PUT /admin/collections/order', { collection_ids: sent.map((r) => r.id) }).subscribe({
      next: (res: any) => {
        // The response is the full list in the new order: the server's truth
        // (display_order, product_count) when it carries one.
        const saved = collectionRowsFromApi(res);
        this.confirmed = saved.length ? saved : sent;
        if (this.resaveQueued) {
          // More moves landed meanwhile: keep them on screen and save them.
          this.saveOrder();
          return;
        }
        this.saving = false;
        if (saved.length) this.rows = saved;
        const m = this.lastMove;
        this.announce(m ? `Order saved. ${m.name} is at position ${this.positionOf(m.id)} of ${this.rows.length}.` : 'Order saved.');
      },
      error: (err: any) => {
        this.saving = false;
        this.resaveQueued = false;
        this.rows = this.confirmed;
        const msg = apiErrorMessage(err, 'Unable to save the new order.');
        // A rejected order (e.g. 422 "Unknown collection id(s)" after another
        // admin deleted one) means this page's list is stale: reload it, or
        // every later move would fail the same way.
        const status = Number(err?.status);
        const stale = status >= 400 && status < 500 && status !== 401 && status !== 403;
        this.toast.error(msg);
        this.announce(
          `The new order was not saved: ${msg} The previous order is back${stale ? ' and the list is being refreshed' : ''}.`,
        );
        const m = this.lastMove;
        if (m?.focus) this.refocus(m.id, m.focus);
        if (stale) this.load(true);
      },
    });
  }

  positionOf(id: number): number {
    return this.rows.findIndex((r) => r.id === id) + 1;
  }

  /**
   * After a re-render, put focus back on the control that moved the row (its
   * handle, or the Move up / Move down button that was pressed). Those
   * buttons are aria-disabled at the ends, never `disabled`, so they can keep
   * focus there.
   */
  private refocus(id: number, control: MoveFocus) {
    setTimeout(() => {
      this.host.nativeElement
        .querySelector<HTMLElement>(`[data-collection-id="${id}"] [data-focus="${control}"]`)
        ?.focus();
    });
  }

  private announce(message: string) {
    this.announcement = '';
    setTimeout(() => (this.announcement = message), 50);
  }

  // ── row actions ──────────────────────────────────────────────────
  edit(row: CollectionRow) {
    this.router.navigate(['/admin/collections/edit'], { queryParams: { id: row.id } });
  }

  confirmDelete(row: CollectionRow): void {
    // A delete mid-reorder would race the order save (its full id list still
    // carries the deleted collection), so it waits for the save to land.
    if (this.saving) {
      this.toast.error('Wait for the new order to finish saving, then delete.');
      return;
    }
    this.confirm.confirm({
      title: 'Delete collection',
      message: `Delete collection "${row.name}"? Its product list is removed; the products themselves are not deleted.`,
      confirmLabel: 'Delete',
      cancelLabel: 'Cancel',
      variant: 'danger',
    }).then((ok) => { if (ok) this.deleteCollection(row); });
  }

  private deleteCollection(row: CollectionRow): void {
    if (this.saving) {
      this.toast.error('Wait for the new order to finish saving, then delete.');
      return;
    }
    this.adapter.delete_v3('DELETE /admin/collections/:id', { params: { id: String(row.id) } }).subscribe({
      next: () => {
        this.toast.success(`Collection "${row.name}" deleted.`);
        this.rows = this.rows.filter((r) => r.id !== row.id);
        this.confirmed = this.confirmed.filter((r) => r.id !== row.id);
        this.load(true);
      },
      error: (err: any) => this.toast.error(apiErrorMessage(err, 'Unable to delete the collection.')),
    });
  }

  onImageError(src: string | null) {
    if (src) this.brokenImages.add(src);
  }

  // ── export (the old table's CSV / XLSX export) ───────────────────
  private readonly exportColumns: AxColumnDef<CollectionRow>[] = [
    { key: 'position', label: 'Position', value: (r) => this.positionOf(r.id) },
    { key: 'name', label: 'Name' },
    { key: 'slug', label: 'Slug' },
    { key: 'is_active', label: 'Status', value: (r) => (r.is_active ? 'Active' : 'Inactive') },
    { key: 'product_count', label: 'Products' },
  ];

  exportAs(format: 'csv' | 'xlsx') {
    this.exporter
      .export(format, this.visibleRows, this.exportColumns, 'collections')
      .catch((err) => this.toast.error(apiErrorMessage(err, 'Export failed.')));
  }

  trackRow = (_: number, r: CollectionRow) => r.id;

  goBack() { this.navHistory.back('/backend'); }
}
