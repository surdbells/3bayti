import { Component, Input, OnChanges, OnDestroy, SimpleChanges } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { Observable, Subscription, throwError } from 'rxjs';
import { catchError, switchMap } from 'rxjs/operators';

import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxMultiselectComponent, AxMultiselectOption } from '../../../shared/forms/ax-multiselect.component';
import {
  CollectionRef,
  collectionRefsFromApi,
} from '../../collections/edit-collection/collection-builder.model';
import { CollectionRow, fetchAllCollections } from '../../collections/collection-list.api';

/**
 * "Collections" section of the admin product page: which storefront
 * collections this product is in, and (with catalog.collections_manage)
 * adding it to more of them or removing it.
 *
 * A product can be in ANY number of collections. Reads
 * GET /admin/products/:id/collections; writes the product's COMPLETE
 * membership set with PUT /admin/products/:id/collections (newly added
 * collections get the product at the END of their curated order; the ones it
 * stays in keep its position). Every write is optimistic: the chips change
 * at once and snap back, with the API's message, if the save fails.
 */
@Component({
  selector: 'app-product-collections',
  standalone: true,
  imports: [CommonModule, FormsModule, RouterLink, IconComponent, AxMultiselectComponent],
  templateUrl: './product-collections.component.html',
  styleUrl: './product-collections.component.css',
})
export class ProductCollectionsComponent implements OnChanges, OnDestroy {
  /** v3 product id. */
  @Input({ required: true }) productId = 0;

  /** The product's collections (saved, or optimistic while a save runs). */
  collections: CollectionRef[] = [];
  loading = false;
  loadError = '';
  saving = false;
  /** Inline confirmation of the last save ('' when none). */
  savedNote = '';

  /** Picker (edit mode) state. */
  editing = false;
  draftIds: number[] = [];
  allCollections: CollectionRow[] | null = null;
  allLoading = false;
  allError = '';

  private loadSub?: Subscription;
  private allSub?: Subscription;
  private noteTimer?: ReturnType<typeof setTimeout>;
  /**
   * Bumped by every save and by a switch to another product: a save response
   * is applied (and clears `saving`) only while it is still the latest one.
   */
  private commitSeq = 0;

  constructor(
    private adapter: PortalCrudAdapter,
    private perms: PermissionService,
    private toast: HotToastService,
  ) {}

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['productId']) {
      this.editing = false;
      this.draftIds = [];
      this.savedNote = '';
      // A save still running for the previous product no longer belongs to
      // this one: don't let it keep this product's controls disabled.
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

  get canManage(): boolean {
    return this.perms.can('catalog.collections_manage');
  }

  /** Picker options: every collection, inactive ones labelled. */
  get options(): AxMultiselectOption[] {
    return (this.allCollections ?? []).map((c) => ({
      id: c.id,
      label: c.is_active ? c.name : `${c.name} (inactive)`,
    }));
  }

  /** The picker selection differs from the current membership. */
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
      .get_v3('GET /admin/products/:id/collections', { params: { id: String(forId) } })
      .subscribe({
        next: (res: any) => {
          if (forId !== this.productId) return;
          this.collections = collectionRefsFromApi(res);
          this.loading = false;
        },
        error: (err: any) => {
          if (forId !== this.productId) return;
          this.loading = false;
          this.loadError = apiErrorMessage(err, 'Unable to load this product\'s collections.');
        },
      });
  }

  // ── edit mode ────────────────────────────────────────────────────
  startEditing(): void {
    if (!this.canManage || this.saving) return;
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
    this.allSub = fetchAllCollections(this.adapter).subscribe({
      next: (rows) => {
        this.allCollections = rows;
        this.allLoading = false;
      },
      error: (err: any) => {
        this.allLoading = false;
        this.allError = apiErrorMessage(err, 'Unable to load the collections list.');
      },
    });
  }

  onDraftChange(ids: (string | number)[] | null): void {
    this.draftIds = (ids ?? []).map((v) => Number(v)).filter((n) => Number.isInteger(n) && n > 0);
  }

  /** Save the picker's selection as the product's complete set. */
  saveDraft(): void {
    if (!this.canManage || this.saving || !this.draftChanged) return;
    const byId = new Map<number, CollectionRef>(this.collections.map((c) => [c.id, c]));
    const fromAll = new Map<number, CollectionRow>((this.allCollections ?? []).map((c) => [c.id, c]));
    const optimistic: CollectionRef[] = this.draftIds.map((id) => {
      const known = byId.get(id);
      if (known) return known;
      const row = fromAll.get(id);
      return { id, name: row?.name ?? `Collection ${id}`, slug: row?.slug ?? '', is_active: row?.is_active ?? true, sort_order: null };
    });
    this.commit(optimistic, () => {
      this.editing = false;
    });
  }

  /**
   * The chip's remove button: drop one collection at once. Not offered while
   * the picker is open (its draft would re-add the collection on save).
   */
  remove(c: CollectionRef): void {
    if (!this.canManage || this.saving || this.editing) return;
    this.commit(this.collections.filter((x) => x.id !== c.id), undefined, `Removed from “${c.name}”.`);
  }

  /**
   * Optimistically apply `next`, then PUT the complete id set (contract D).
   * Success adopts the server's list; failure restores the previous chips
   * and shows the API's message.
   *
   * D replaces the whole set, so the set is re-read first and only THIS
   * change (the collections added / removed here) is applied on top of it: a
   * membership changed elsewhere since this card loaded (a collection's
   * product builder, another tab) is kept instead of silently undone.
   */
  private commit(next: CollectionRef[], onSuccess?: () => void, successMessage?: string): void {
    const previous = this.collections;
    const prevIds = new Set(previous.map((c) => c.id));
    const nextIds = new Set(next.map((c) => c.id));
    const addedIds = next.filter((c) => !prevIds.has(c.id)).map((c) => c.id);
    const removedIds = new Set(previous.filter((p) => !nextIds.has(p.id)).map((p) => p.id));
    const added = addedIds.length;
    const removed = removedIds.size;
    const forId = this.productId;
    const seq = ++this.commitSeq;
    this.collections = next;
    this.saving = true;
    this.savedNote = '';
    // Read the stored set, apply this change on top, and write it with that
    // set as the precondition (expected_collection_ids). If another writer
    // slips in between, the API answers 409 CONFLICT_STALE and the read +
    // write is retried (a couple of times) on the fresh set.
    const attempt = (retriesLeft: number): Observable<any> =>
      this.adapter
        .get_v3('GET /admin/products/:id/collections', { params: { id: String(forId) } })
        .pipe(
          switchMap((res: any) => {
            const stored = collectionRefsFromApi(res).map((c) => c.id);
            const ids = [...stored.filter((id) => !removedIds.has(id))];
            for (const id of addedIds) if (!ids.includes(id)) ids.push(id);
            return this.adapter.put_v3(
              'PUT /admin/products/:id/collections',
              { collection_ids: ids, expected_collection_ids: stored },
              { params: { id: String(forId) } },
            );
          }),
          catchError((err: any) =>
            err?.status === 409 && err?.error?.error?.code === 'CONFLICT_STALE' && retriesLeft > 0
              ? attempt(retriesLeft - 1)
              : throwError(() => err),
          ),
        );
    attempt(2)
      .subscribe({
        next: (res: any) => {
          if (seq !== this.commitSeq) return;
          this.saving = false;
          const saved = collectionRefsFromApi(res);
          // The response is the saved set; an empty data array is a real
          // "in no collections", so only fall back when it isn't a list.
          this.collections = Array.isArray(res?.data) || Array.isArray(res) ? saved : next;
          const msg = successMessage ?? this.changeSummary(added, removed);
          this.note(msg);
          this.toast.success(msg);
          onSuccess?.();
        },
        error: (err: any) => {
          if (seq !== this.commitSeq) return;
          this.saving = false;
          this.collections = previous;
          this.toast.error(apiErrorMessage(err, 'Unable to update this product\'s collections.'));
        },
      });
  }

  private changeSummary(added: number, removed: number): string {
    const parts: string[] = [];
    if (added) parts.push(`added to ${added} collection${added === 1 ? '' : 's'}`);
    if (removed) parts.push(`removed from ${removed}`);
    const text = parts.join(' and ') || 'collections saved';
    return text.charAt(0).toUpperCase() + text.slice(1) + '.';
  }

  private note(message: string): void {
    this.savedNote = message;
    if (this.noteTimer) clearTimeout(this.noteTimer);
    this.noteTimer = setTimeout(() => (this.savedNote = ''), 6000);
  }

  trackById = (_: number, c: CollectionRef) => c.id;
}
