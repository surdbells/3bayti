import { Component, OnDestroy, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable, Subject, Subscription, of, throwError } from 'rxjs';
import { catchError, debounceTime, map, switchMap } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { IconComponent } from '../../shared/icon/icon.component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import {
  VendorCollectionRow,
  VendorProductCard,
  fetchAllVendorCollections,
  isStaleConflict,
  vendorProductCardsFromApi,
} from './vendor-collections.api';

/** How many of the vendor's products a picker search shows. */
const SEARCH_LIMIT = 20;

/**
 * Vendor "Collections" page (/my-collections): every ACTIVE storefront
 * collection with how many of the vendor's own products are in it, and, per
 * collection, a panel to add or remove the vendor's own products.
 *
 * Collections stay admin-curated: a vendor only ever sees and changes THEIR
 * products in a collection (GET/PUT /vendor/collections/:id/products). Added
 * products go to the end of the collection's line-up; other stores' products
 * and the admin's order are never touched. Every write re-reads the stored
 * set, applies only this change, and PUTs it with `expected_product_ids`,
 * retrying twice on 409 CONFLICT_STALE.
 */
@Component({
  selector: 'app-vendor-collections',
  standalone: true,
  imports: [CommonModule, FormsModule, IconComponent, VendorShellComponent, TranslatePipe],
  templateUrl: './vendor-collections.component.html',
  styleUrl: './vendor-collections.component.css',
})
export class VendorCollectionsComponent implements OnInit, OnDestroy {
  collections: VendorCollectionRow[] = [];
  loading = false;
  loadError = '';

  /** The collection whose panel is open (null = none). */
  openId: number | null = null;
  /** The vendor's own products in the open collection. */
  members: VendorProductCard[] = [];
  membersLoading = false;
  membersError = '';
  saving = false;

  /** Picker search over the vendor's own products. */
  search = '';
  results: VendorProductCard[] = [];
  searching = false;
  searchError = '';

  private loadSub?: Subscription;
  private membersSub?: Subscription;
  private searchSub?: Subscription;
  private search$ = new Subject<string>();
  /** A save response is applied only while it is still the latest one. */
  private commitSeq = 0;

  constructor(
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private i18n: I18nService,
  ) {}

  ngOnInit(): void {
    this.searchSub = this.search$
      .pipe(
        debounceTime(250),
        switchMap((term) => this.searchProducts(term)),
      )
      .subscribe((rows) => {
        this.results = rows;
        this.searching = false;
      });
    this.load();
  }

  ngOnDestroy(): void {
    this.loadSub?.unsubscribe();
    this.membersSub?.unsubscribe();
    this.searchSub?.unsubscribe();
  }

  load(): void {
    this.loadSub?.unsubscribe();
    this.loading = true;
    this.loadError = '';
    this.loadSub = fetchAllVendorCollections(this.adapter).subscribe({
      next: (rows) => {
        this.collections = rows;
        this.loading = false;
        if (this.openId != null && !rows.some((c) => c.id === this.openId)) this.close();
      },
      error: (err: any) => {
        this.loading = false;
        this.loadError = apiErrorMessage(err, this.i18n.t('vendor_collections.collections_list_load_failed'));
      },
    });
  }

  get openCollection(): VendorCollectionRow | null {
    return this.collections.find((c) => c.id === this.openId) ?? null;
  }

  isMember(p: VendorProductCard): boolean {
    return this.members.some((m) => m.id === p.id);
  }

  toggle(c: VendorCollectionRow): void {
    if (this.openId === c.id) {
      this.close();
      return;
    }
    this.openId = c.id;
    this.commitSeq++;
    this.saving = false;
    this.search = '';
    this.results = [];
    this.searchError = '';
    this.loadMembers();
    this.onSearch('');
  }

  close(): void {
    this.openId = null;
    this.membersSub?.unsubscribe();
    this.members = [];
    this.commitSeq++;
    this.saving = false;
  }

  loadMembers(): void {
    const forId = this.openId;
    if (forId == null) return;
    this.membersSub?.unsubscribe();
    this.members = [];
    this.membersLoading = true;
    this.membersError = '';
    this.membersSub = this.adapter
      .get_v3('GET /vendor/collections/:id/products', { params: { id: String(forId) } })
      .subscribe({
        next: (res: any) => {
          if (forId !== this.openId) return;
          this.members = vendorProductCardsFromApi(res);
          this.membersLoading = false;
          this.setCount(forId, this.members.length);
        },
        error: (err: any) => {
          if (forId !== this.openId) return;
          this.membersLoading = false;
          this.membersError = apiErrorMessage(err, this.i18n.t('vendor_collections.products_load_failed'));
        },
      });
  }

  onSearch(term: string): void {
    this.searching = true;
    this.searchError = '';
    this.search$.next(term.trim());
  }

  add(p: VendorProductCard): void {
    if (this.saving || this.isMember(p)) return;
    this.commit([...this.members, p], [p.id], [], this.i18n.t('vendor_collections.product_added', { name: p.name }));
  }

  remove(p: VendorProductCard): void {
    if (this.saving) return;
    this.commit(this.members.filter((m) => m.id !== p.id), [], [p.id], this.i18n.t('vendor_collections.product_removed', { name: p.name }));
  }

  private searchProducts(term: string): Observable<VendorProductCard[]> {
    const query: Record<string, string | number> = { limit: SEARCH_LIMIT, offset: 0 };
    if (term) query['search'] = term;
    return this.adapter.get_v3('GET /vendor/products', { query }).pipe(
      map((res: any) => vendorProductCardsFromApi(res)),
      catchError((err: any) => {
        this.searchError = apiErrorMessage(err, this.i18n.t('vendor_collections.products_load_failed'));
        return of([] as VendorProductCard[]);
      }),
    );
  }

  /**
   * Optimistically show `next`, then write the stored set with only this
   * change applied (contract: the complete set of the vendor's own ids, with
   * the set it read as `expected_product_ids`).
   */
  private commit(next: VendorProductCard[], addIds: number[], removeIds: number[], successMessage: string): void {
    const forId = this.openId;
    if (forId == null) return;
    const previous = this.members;
    const removed = new Set(removeIds);
    const seq = ++this.commitSeq;
    this.members = next;
    this.saving = true;
    const attempt = (retriesLeft: number): Observable<any> =>
      this.adapter.get_v3('GET /vendor/collections/:id/products', { params: { id: String(forId) } }).pipe(
        switchMap((res: any) => {
          const stored = vendorProductCardsFromApi(res).map((p) => p.id);
          const ids = stored.filter((id) => !removed.has(id));
          for (const id of addIds) if (!ids.includes(id)) ids.push(id);
          return this.adapter.put_v3(
            'PUT /vendor/collections/:id/products',
            { product_ids: ids, expected_product_ids: stored },
            { params: { id: String(forId) } },
          );
        }),
        catchError((err: any) =>
          isStaleConflict(err) && retriesLeft > 0 ? attempt(retriesLeft - 1) : throwError(() => err),
        ),
      );
    attempt(2).subscribe({
      next: (res: any) => {
        if (seq !== this.commitSeq) return;
        this.saving = false;
        if (Array.isArray(res?.data) || Array.isArray(res)) this.members = vendorProductCardsFromApi(res);
        this.setCount(forId, this.members.length);
        this.toast.success(successMessage);
      },
      error: (err: any) => {
        if (seq !== this.commitSeq) return;
        this.saving = false;
        this.members = previous;
        this.toast.error(apiErrorMessage(err, this.i18n.t('vendor_collections.collections_update_failed')));
        // The collection may have been deactivated meanwhile: refresh the list.
        if (err?.status === 404) this.load();
      },
    });
  }

  private setCount(collectionId: number, count: number): void {
    this.collections = this.collections.map((c) => (c.id === collectionId ? { ...c, my_product_count: count } : c));
  }

  trackById = (_: number, x: { id: number }) => x.id;
}
