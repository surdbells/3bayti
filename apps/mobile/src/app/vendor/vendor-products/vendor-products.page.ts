import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonCard,
  IonSearchbar,
  IonInfiniteScroll,
  IonInfiniteScrollContent,
  NavController,
  ActionSheetController,
  AlertController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { InfiniteScrollCustomEvent } from '@ionic/angular';
import { firstValueFrom } from 'rxjs';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { apiErrorMessage } from '../../core/http/api-error';
import { I18nService } from '../../i18n.service';

/**
 * Vendor's own products list with light "quick edit" actions.
 *
 * Backend contract:
 *   GET /v3/vendor/products?limit&offset&search  -> vendorManageShape[]
 *   PUT /v3/vendor/products/{id}                 -> partial update
 *   GET /v3/vendor/collections?limit&offset      -> active collections
 *   GET /v3/vendor/products/{id}/collections     -> product's active memberships
 *   PUT /v3/vendor/products/{id}/collections     -> { collection_ids,
 *                                                    expected_collection_ids }
 *
 * Editing is intentionally shallow (price, stock status, publish/unpublish,
 * collections). Full create/edit (images, variants, category) stays on the
 * portal/web - the API's PUT applies only the fields present, so a single
 * field can be patched without touching the rest of the product.
 *
 * Collections are admin-curated; a vendor can only add/remove their OWN
 * product to/from active collections (the collection_products join table,
 * never the legacy products.collection_id). New memberships land at the end
 * of the collection's curation order, server-side.
 */
interface VendorProduct {
  id: number;
  name: string;
  image: string | null;
  price: number;
  price_formatted: string;
  status: string;        // published | draft
  stock_status: string;  // in_stock | out_of_stock | on_backorder
  stock_quantity: number;
}

/** Active collection option offered to a vendor. */
export interface VendorCollectionOption {
  id: number;
  name: string;
}

/** Page size for GET /vendor/collections (the API caps limit at 100). */
const COLLECTIONS_PAGE = 100;
/** Hard stop when paging the collection list (100 x 10 = 1000 collections). */
const COLLECTIONS_MAX_PAGES = 10;

@Component({
  selector: 'app-vendor-products',
  templateUrl: './vendor-products.page.html',
  styleUrls: ['./vendor-products.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonCard,
    IonSearchbar,
    IonInfiniteScroll,
    IonInfiniteScrollContent,
    CommonModule,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
  ],
})
export class VendorProductsPage implements OnInit {
  products: VendorProduct[] = [];
  search = '';

  ui_controls = {
    is_loading: false,
    is_empty: false,
    has_more: true,
  };

  private initial = {
    token: '',
    limit: 20,
    offset: 0,
  };

  constructor(
    private nav: NavController,
    private router: Router,
    private toast: AxNotificationService,
    private adapter: MobileNetworkAdapter,
    private actionSheet: ActionSheetController,
    private alertCtrl: AlertController,
    private i18n: I18nService,
  ) {}

  async ngOnInit() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
      return;
    }
    const user = JSON.parse(ret.value);
    this.initial.token = user.token;

    if (!user.is_vendor) {
      this.toast.error(this.i18n.t('vendor_access_required'), { position: 'top-center' });
      this.router.navigate(['/', 'home']);
      return;
    }

    this.loadProducts(true);
  }

  goBack() {
    this.nav.back();
  }

  onSearch() {
    this.loadProducts(true);
  }

  onImgError(event: Event) {
    (event.target as HTMLImageElement).style.display = 'none';
  }

  onIonInfinite(event: InfiniteScrollCustomEvent) {
    if (!this.ui_controls.has_more) {
      event.target.complete();
      return;
    }
    this.initial.offset += this.initial.limit;
    this.loadProducts(false).then(() => event.target.complete());
  }

  private loadProducts(reset: boolean): Promise<void> {
    return new Promise((resolve) => {
      if (reset) {
        this.initial.offset = 0;
        this.products = [];
        this.ui_controls.is_loading = true;
      }

      const queryParams: Record<string, string | number> = {
        limit: this.initial.limit,
        offset: this.initial.offset,
      };
      const term = this.search.trim();
      if (term) queryParams['search'] = term;

      this.adapter
        .get_v3('GET /vendor/products', { authToken: this.initial.token, queryParams })
        .subscribe({
          next: (response: any) => {
            this.ui_controls.is_loading = false;
            if (response.response_code === 200 && response.status === 'success') {
              const rows = this.extract(response.data);
              this.products.push(...rows);
              this.ui_controls.is_empty = this.products.length === 0;
              this.ui_controls.has_more = rows.length >= this.initial.limit;
            } else {
              this.ui_controls.is_empty = this.products.length === 0;
              this.ui_controls.has_more = false;
              if (response.message) this.toast.error(response.message, { position: 'top-center' });
            }
            resolve();
          },
          error: (err: any) => {
            this.ui_controls.is_loading = false;
            this.toast.error(apiErrorMessage(err, this.i18n.t('network_error_retry')), { position: 'top-center' });
            resolve();
          },
        });
    });
  }

  private extract(data: any): VendorProduct[] {
    const arr = Array.isArray(data)
      ? data
      : Array.isArray(data?.items)
        ? data.items
        : Array.isArray(data?.products)
          ? data.products
          : [];
    return arr.map((p: any) => ({
      id: p.id,
      name: p.name ?? '',
      image: p.image ?? p.primary_image?.url ?? null,
      price: Number(p.price ?? 0),
      price_formatted: p.price_formatted ?? `AED ${Number(p.price ?? 0).toFixed(2)}`,
      status: p.status ?? 'draft',
      stock_status: p.stock_status ?? 'in_stock',
      stock_quantity: Number(p.stock_quantity ?? p.quantity ?? 0),
    }));
  }

  // ── Quick edits ────────────────────────────────────────────────────
  async openActions(p: VendorProduct) {
    const isOut = p.stock_status === 'out_of_stock';
    const isDraft = p.status !== 'published';
    const sheet = await this.actionSheet.create({
      header: p.name,
      buttons: [
        { text: this.i18n.t('edit_price'), handler: () => { void this.promptPrice(p); } },
        {
          text: this.i18n.t(isOut ? 'mark_in_stock' : 'mark_out_of_stock'),
          handler: () => { this.setStockStatus(p, isOut ? 'in_stock' : 'out_of_stock'); },
        },
        {
          text: this.i18n.t(isDraft ? 'publish_product' : 'unpublish_product'),
          handler: () => { this.setStatus(p, isDraft ? 'published' : 'draft'); },
        },
        { text: this.i18n.t('manage_collections'), handler: () => { void this.openCollections(p); } },
        { text: this.i18n.t('cancel'), role: 'cancel' },
      ],
    });
    await sheet.present();
  }

  async promptPrice(p: VendorProduct) {
    const alert = await this.alertCtrl.create({
      header: this.i18n.t('edit_price'),
      subHeader: p.name,
      inputs: [{ name: 'price', type: 'number', value: p.price, min: 0, attributes: { step: '0.01' } }],
      buttons: [
        { text: this.i18n.t('cancel'), role: 'cancel' },
        {
          text: this.i18n.t('save'),
          handler: (v: { price: string }) => {
            const n = Number(v.price);
            if (isNaN(n) || n < 0) {
              this.toast.error(this.i18n.t('invalid_price'), { position: 'top-center' });
              return false;
            }
            this.savePrice(p, n);
            return true;
          },
        },
      ],
    });
    await alert.present();
  }

  private savePrice(p: VendorProduct, price: number) {
    this.update(p, { price: price.toFixed(2) }, () => {
      p.price = price;
      p.price_formatted = `AED ${price.toFixed(2)}`;
    });
  }

  private setStockStatus(p: VendorProduct, stock_status: string) {
    this.update(p, { stock_status }, () => { p.stock_status = stock_status; });
  }

  private setStatus(p: VendorProduct, status: string) {
    this.update(p, { status }, () => { p.status = status; });
  }

  /** Issue the partial PUT, applying the local change only on success. */
  private update(p: VendorProduct, body: Record<string, unknown>, apply: () => void) {
    this.adapter
      .put_v3('PUT /vendor/products/:id', body, {
        authToken: this.initial.token,
        pathParams: { id: String(p.id) },
      })
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            apply();
            this.toast.success(this.i18n.t('product_updated'), { position: 'top-center' });
          } else {
            this.toast.error(response.message ?? this.i18n.t('update_failed'), { position: 'top-center' });
          }
        },
        error: (err: any) => this.toast.error(apiErrorMessage(err, this.i18n.t('update_failed')), { position: 'top-center' }),
      });
  }

  // ── Collections ────────────────────────────────────────────────────
  /**
   * Checkbox picker of active collections for one of the vendor's products.
   * Loads the options and the product's current memberships in parallel, then
   * saves the full chosen set with the loaded set as the optimistic
   * precondition (409 CONFLICT_STALE when it changed elsewhere meanwhile).
   */
  async openCollections(p: VendorProduct): Promise<void> {
    let options: VendorCollectionOption[];
    let current: number[];
    try {
      [options, current] = await Promise.all([
        this.loadAllCollections(),
        this.loadProductCollectionIds(p.id),
      ]);
    } catch (e: any) {
      this.toast.error(apiErrorMessage(e, this.i18n.t('network_error_retry')), { position: 'top-center' });
      return;
    }

    if (options.length === 0) {
      this.toast.info(this.i18n.t('no_active_collections'), { position: 'top-center' });
      return;
    }

    const selected = new Set(current);
    const alert = await this.alertCtrl.create({
      header: this.i18n.t('manage_collections'),
      subHeader: p.name,
      message: this.i18n.t('collections_hint'),
      inputs: options.map((c) => ({
        type: 'checkbox' as const,
        label: c.name,
        value: c.id,
        checked: selected.has(c.id),
      })),
      buttons: [
        { text: this.i18n.t('cancel'), role: 'cancel' },
        {
          text: this.i18n.t('save'),
          handler: (values: unknown) => {
            this.saveCollections(p, collectionIdsFromValues(values), current);
            return true;
          },
        },
      ],
    });
    await alert.present();
  }

  /** Page through GET /vendor/collections (the API returns active ones only). */
  private async loadAllCollections(): Promise<VendorCollectionOption[]> {
    const out: VendorCollectionOption[] = [];
    for (let page = 0; page < COLLECTIONS_MAX_PAGES; page++) {
      const response: any = await firstValueFrom(
        this.adapter.get_v3('GET /vendor/collections', {
          authToken: this.initial.token,
          queryParams: { limit: COLLECTIONS_PAGE, offset: page * COLLECTIONS_PAGE },
        }),
      );
      if (!(response?.response_code === 200 && response?.status === 'success')) {
        throw { message: response?.message };
      }
      const rows: any[] = Array.isArray(response.data) ? response.data : [];
      for (const r of rows) {
        const id = Number(r?.id);
        if (Number.isInteger(id) && id > 0) out.push({ id, name: String(r?.name ?? '') });
      }
      const total = Number(response.meta?.total);
      if (rows.length < COLLECTIONS_PAGE || (Number.isFinite(total) && out.length >= total)) break;
    }
    return out;
  }

  /** GET /vendor/products/:id/collections -> ids of its active collections. */
  private async loadProductCollectionIds(productId: number): Promise<number[]> {
    const response: any = await firstValueFrom(
      this.adapter.get_v3('GET /vendor/products/:id/collections', {
        authToken: this.initial.token,
        pathParams: { id: String(productId) },
      }),
    );
    if (!(response?.response_code === 200 && response?.status === 'success')) {
      throw { message: response?.message };
    }
    return collectionIdsFromRows(response.data);
  }

  /** PUT the full chosen set; no request when nothing changed. */
  private saveCollections(p: VendorProduct, ids: number[], expected: number[]) {
    if (sameIdSet(ids, expected)) return;
    this.adapter
      .put_v3(
        'PUT /vendor/products/:id/collections',
        { collection_ids: ids, expected_collection_ids: expected },
        { authToken: this.initial.token, pathParams: { id: String(p.id) } },
      )
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.toast.success(this.i18n.t('collections_saved'), { position: 'top-center' });
          } else if (response.response_code === 409 && response.error_code === 'CONFLICT_STALE') {
            this.toast.error(this.i18n.t('collections_changed_reload'), { position: 'top-center' });
          } else {
            this.toast.error(response.message || this.i18n.t('collections_update_failed'), { position: 'top-center' });
          }
        },
        error: (err: any) =>
          this.toast.error(apiErrorMessage(err, this.i18n.t('collections_update_failed')), { position: 'top-center' }),
      });
  }

  // ── Display helpers ────────────────────────────────────────────────
  stockClass(s: string): string {
    return s === 'in_stock' ? 'in' : s === 'out_of_stock' ? 'out' : 'back';
  }
}

/** Ids from a `[{ id, ... }]` collection list payload. */
export function collectionIdsFromRows(data: unknown): number[] {
  const rows = Array.isArray(data) ? data : [];
  return rows.map((r: any) => Number(r?.id)).filter((n) => Number.isInteger(n) && n > 0);
}

/** Ids from the checkbox alert's selected values (de-duplicated). */
export function collectionIdsFromValues(values: unknown): number[] {
  const arr = Array.isArray(values) ? values : [];
  return [...new Set(arr.map((v) => Number(v)).filter((n) => Number.isInteger(n) && n > 0))];
}

/** Order-insensitive id set equality. */
export function sameIdSet(a: number[], b: number[]): boolean {
  const sa = new Set(a);
  const sb = new Set(b);
  if (sa.size !== sb.size) return false;
  for (const x of sa) if (!sb.has(x)) return false;
  return true;
}
