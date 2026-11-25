import { Component, Input, OnChanges, OnDestroy, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Observable, Subscription, throwError } from 'rxjs';
import { catchError, switchMap } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { apiErrorMessage } from '../../shared/http/api-error';
import { IconComponent } from '../../shared/icon/icon.component';
import { AxMultiselectComponent, AxMultiselectOption } from '../../shared/forms/ax-multiselect.component';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import {
  VendorCollectionRef,
  VendorCollectionRow,
  fetchAllVendorCollections,
  isStaleConflict,
  vendorCollectionRefsFromApi,
} from '../vendor-collections/vendor-collections.api';

/**
 * "Collections" section of the VENDOR product edit page: which active
 * storefront collections this (own) product is in, and adding it to more of
 * them or taking it out.
 *
 * Reads GET /vendor/products/:id/collections; writes the product's complete
 * set of ACTIVE collections with PUT /vendor/products/:id/collections
 * (newly added collections get the product at the END of their curated
 * order; ones it stays in keep its position; inactive memberships the vendor
 * can't see are kept server-side). It saves on its own, independent of the
 * product form, and never touches the legacy products.collection_id.
 *
 * Every write is optimistic: re-read the stored set, apply only this change
 * on top, PUT it with that set as `expected_collection_ids`, and retry twice
 * on 409 CONFLICT_STALE (an admin or another tab changed it in between).
 */
@Component({
  selector: 'app-vendor-product-collections',
  standalone: true,
  imports: [CommonModule, FormsModule, IconComponent, AxMultiselectComponent, TranslatePipe],
  templateUrl: './vendor-product-collections.component.html',
  styleUrl: './vendor-product-collections.component.css',
})
export class VendorProductCollectionsComponent implements OnChanges, OnDestroy {
  /** v3 product id. */
  @Input({ required: true }) productId = 0;

  /** The product's collections (saved, or optimistic while a save runs). */
  collections: VendorCollectionRef[] = [];
  loading = false;
  loadError = '';
  saving = false;
  savedNote = '';

  editing = false;
  draftIds: number[] = [];
  allCollections: VendorCollectionRow[] | null = null;
  allLoading = false;
  allError = '';

  private loadSub?: Subscription;
  private allSub?: Subscription;
  private noteTimer?: ReturnType<typeof setTimeout>;
  /** A save response is applied only while it is still the latest one. */
  private commitSeq = 0;

  constructor(
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
    private i18n: I18nService,
  ) {}

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['productId']) {
      this.editing = false;
      this.draftIds = [];
      this.savedNote = '';
      this.commitSeq++;
      this.saving = false;
      this.load();
    }
  }

  ngOnDestroy(): void {
    this.loadSub?.unsubscribe();
    this.allSub?.unsubscribe();
    if (this.noteTimer) clearTimeout(this.noteTimer);
  }

  get options(): AxMultiselectOption[] {
    return (this.allCollections ?? []).map((c) => ({ id: c.id, label: c.name }));
  }

  get draftChanged(): boolean {
    const now = new Set(this.collections.map((c) => c.id));
    return this.draftIds.length !== now.size || this.draftIds.some((id) => !now.has(id));
  }

  load(): void {
    this.loadSub?.unsubscribe();
    this.collections = [];
    this.loadError = '';
    if (!this.productId) {
      this.loading = false;
      return;
    }
    const forId = this.productId;
    this.loading = true;
    this.loadSub = this.adapter
      .get_v3('GET /vendor/products/:id/collections', { params: { id: String(forId) } })
      .subscribe({
        next: (res: any) => {
          if (forId !== this.productId) return;
          this.collections = vendorCollectionRefsFromApi(res);
          this.loading = false;
        },
        error: (err: any) => {
          if (forId !== this.productId) return;
          this.loading = false;
          this.loadError = apiErrorMessage(err, this.i18n.t('vendor_collections.collections_load_failed'));
        },
      });
  }

  startEditing(): void {
    if (this.saving) return;
    this.editing = true;
    this.draftIds = this.collections.map((c) => c.id);
    if (!this.allCollections && !this.allLoading) this.loadAllCollections();
  }

  cancelEditing(): void {
    this.editing = false;
    this.draftIds = [];
  }

  loadAllCollections(): void {
    this.allSub?.unsubscribe();
    this.allLoading = true;
    this.allError = '';
    this.allSub = fetchAllVendorCollections(this.adapter).subscribe({
      next: (rows) => {
        this.allCollections = rows;
        this.allLoading = false;
      },
      error: (err: any) => {
        this.allLoading = false;
        this.allError = apiErrorMessage(err, this.i18n.t('vendor_collections.collections_list_load_failed'));
      },
    });
  }

  onDraftChange(ids: (string | number)[] | null): void {
    this.draftIds = (ids ?? []).map((v) => Number(v)).filter((n) => Number.isInteger(n) && n > 0);
  }

  saveDraft(): void {
    if (this.saving || !this.draftChanged) return;
    const byId = new Map<number, VendorCollectionRef>(this.collections.map((c) => [c.id, c]));
    const fromAll = new Map<number, VendorCollectionRow>((this.allCollections ?? []).map((c) => [c.id, c]));
    const optimistic: VendorCollectionRef[] = this.draftIds.map((id) => {
      const known = byId.get(id);
      if (known) return known;
      const row = fromAll.get(id);
      return { id, name: row?.name ?? `#${id}`, slug: row?.slug ?? '' };
    });
    this.commit(optimistic, () => {
      this.editing = false;
    });
  }

  remove(c: VendorCollectionRef): void {
    if (this.saving || this.editing) return;
    this.commit(
      this.collections.filter((x) => x.id !== c.id),
      undefined,
      this.i18n.t('vendor_collections.collections_removed_from', { name: c.name }),
    );
  }

  private commit(next: VendorCollectionRef[], onSuccess?: () => void, successMessage?: string): void {
    const previous = this.collections;
    const prevIds = new Set(previous.map((c) => c.id));
    const nextIds = new Set(next.map((c) => c.id));
    const addedIds = next.filter((c) => !prevIds.has(c.id)).map((c) => c.id);
    const removedIds = new Set(previous.filter((p) => !nextIds.has(p.id)).map((p) => p.id));
    const forId = this.productId;
    const seq = ++this.commitSeq;
    this.collections = next;
    this.saving = true;
    this.savedNote = '';
    const attempt = (retriesLeft: number): Observable<any> =>
      this.adapter
        .get_v3('GET /vendor/products/:id/collections', { params: { id: String(forId) } })
        .pipe(
          switchMap((res: any) => {
            const stored = vendorCollectionRefsFromApi(res).map((c) => c.id);
            const ids = stored.filter((id) => !removedIds.has(id));
            for (const id of addedIds) if (!ids.includes(id)) ids.push(id);
            return this.adapter.put_v3(
              'PUT /vendor/products/:id/collections',
              { collection_ids: ids, expected_collection_ids: stored },
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
        this.collections = Array.isArray(res?.data) || Array.isArray(res) ? vendorCollectionRefsFromApi(res) : next;
        const msg = successMessage ?? this.i18n.t('vendor_collections.collections_saved');
        this.note(msg);
        this.toast.success(msg);
        onSuccess?.();
      },
      error: (err: any) => {
        if (seq !== this.commitSeq) return;
        this.saving = false;
        this.collections = previous;
        this.toast.error(apiErrorMessage(err, this.i18n.t('vendor_collections.collections_update_failed')));
      },
    });
  }

  private note(message: string): void {
    this.savedNote = message;
    if (this.noteTimer) clearTimeout(this.noteTimer);
    this.noteTimer = setTimeout(() => (this.savedNote = ''), 6000);
  }

  trackById = (_: number, c: VendorCollectionRef) => c.id;
}
