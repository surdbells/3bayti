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
 *
 * Editing is intentionally shallow (price, stock status, publish/unpublish).
 * Full create/edit (images, variants, category) stays on the portal/web —
 * the API's PUT applies only the fields present, so a single field can be
 * patched without touching the rest of the product.
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

  // ── Display helpers ────────────────────────────────────────────────
  stockClass(s: string): string {
    return s === 'in_stock' ? 'in' : s === 'out_of_stock' ? 'out' : 'back';
  }
}
