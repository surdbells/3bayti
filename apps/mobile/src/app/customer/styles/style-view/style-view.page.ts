import { Component, OnInit, OnDestroy, ChangeDetectorRef, ChangeDetectionStrategy } from '@angular/core';
import { CommonModule, DecimalPipe } from '@angular/common';
import { FormsModule } from '@angular/forms';
import {
  AlertController,
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
  Platform
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from "@angular/router";
import { AxNotificationService } from '../../../shared/ax-mobile/notification';
import { Preferences } from "@capacitor/preferences";
import { Subscription, firstValueFrom } from 'rxjs';
import {TranslatePipe} from "../../../translate.pipe";
import {Labels} from "../../../class/labels";
import {ConnectionService} from "../../../service/connection.service";
import {NetworkService} from "../../../service/network.service";
import {MobileNetworkAdapter} from "../../../core/http/mobile-network-adapter";
import {apiErrorMessage} from "../../../core/http/api-error";
import {GlobalComponent} from "../../../global-component";

import { AxIconComponent } from '../../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../../shared/app-tab-bar';
import { AxWishlistSheetComponent } from '../../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../../core/services/wishlist.service';
import { I18nService } from '../../../i18n.service';

export interface StyleProduct {
  product_id: number;
  product_name: string;
  price: number;
  image: string;
}

export interface Styles {
  id: number;
  slug: string;
  total_price: number;
  category: string;
  style_name: string;
  products: StyleProduct[];
  /** True when the authenticated viewer created this look (detail fetch only). */
  is_owner?: boolean;
}

@Component({
  selector: 'app-style-view',
  templateUrl: './style-view.page.html',
  styleUrls: ['./style-view.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    FormsModule,
    DecimalPipe,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    TranslatePipe,
    AxIconComponent,
    AxWishlistSheetComponent,
    AppTabBarComponent
  ]
})
export class StyleViewPage implements OnInit, OnDestroy {
  isOnline = true;
  isWishOpen = false;
  /** True while POST /me/wishlist/labels is in flight (inline label create). */
  isCreatingLabel = false;
  categories: Labels[] = [];
  // Undefined until loaded (router state fast-path OR slug re-fetch). The
  // template gates on @if(style) and shows skeletons while it's undefined.
  style?: Styles;

  // Image loading tracking
  imageLoaded: { [key: number]: boolean } = {};

  /** True while "Add the look to cart" is fanning out the per-item adds. */
  isAddingLook = false;
  /** True while a soft-delete is in flight. */
  isDeleting = false;

  private sub: Subscription | null = null;

  addCloset = {
    id: 0,
    token: "",
    label_id: 0,
    product_id: 0,
    product_name: "",
    product_image: ""
  }

  rqst_param = {
    id: 0,
    token: ""
  }

  single_user = {
    id: 0,
    token: "",
    first_name: "",
    last_name: "",
    user_type: "",
    email: "",
    phone: "",
    avatar: "",
    location: "",
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  }

  ui_controls = {
    is_loading: false,
    is_empty: false,
    is_loading_category: false
  }

  // ── Restyle with Ain ──────────────────────────────────────────────────
  restyleInstruction = '';
  restyleLoading = false;
  restyleSaving = false;
  restylePreview: { cards: any[]; rationale: string; interactionId: number | null } | null = null;
  readonly restyleSuggestions = ['style_restyle_s1', 'style_restyle_s2', 'style_restyle_s3'];
  private ainSessionId = '';

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private platform: Platform,
    private nav: NavController,
    private net: ConnectionService,
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private wishlistService: WishlistService,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private alertCtrl: AlertController,
    private cdr: ChangeDetectorRef
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }

  async ngOnInit() {
    // Resolve the signed-in user first (the style-view is auth-only; guests
    // are bounced to /login by getObject). From here single_user.token is set,
    // which the ownership + edit/delete paths rely on.
    await this.getObject();
    if (!this.single_user.token) {
      return;
    }

    // Fast path: the style was passed in router state from the list. On a
    // hard reload / deep link that state is wiped, so fall back to fetching
    // the style by its slug (from the route) and rebuilding it, instead of
    // bouncing back to /styles, which broke deep links + refresh.
    this.style = history.state?.style;
    const slug = this.route.snapshot.paramMap.get('slug');
    if (!this.style) {
      if (slug) {
        this.loadStyleBySlug(slug);
      } else {
        // No state AND no slug, nothing to render; return to the hub.
        this.router.navigate(['/styles']);
        return;
      }
    } else if (this.style.is_owner === undefined) {
      // A style from the list fast-path carries no is_owner (the list shapes
      // don't compute it), so resolve it authoritatively for the Edit/Delete
      // controls. loadStyleBySlug already carries is_owner.
      this.resolveOwnership();
    }
    void this.ensureAinSession();
  }

  /**
   * Whether the signed-in viewer owns this look (drives Edit/Delete).
   */
  isOwner(): boolean {
    return this.style?.is_owner === true;
  }

  /**
   * Resolve is_owner for a style that arrived via router state. Re-fetches
   * the authenticated detail purely to read is_owner and merges it in, so
   * the owner's Edit/Delete controls appear a moment after the fast render.
   */
  private resolveOwnership(): void {
    const slug = this.style?.slug;
    if (!slug || !this.single_user.token) {
      return;
    }
    this.networkAdapter.get_v3('GET /mobile/style-detail', {
      pathParams: { slug },
      authToken: this.single_user.token,
    }).subscribe({
      next: (res: any) => {
        if (res?.response_code === 200 && res?.status === 'success' && res?.data && this.style) {
          this.style = { ...this.style, is_owner: res.data.is_owner === true };
          this.cdr.markForCheck();
        }
      },
      error: () => {
        // Ownership stays unknown; the controls simply stay hidden.
      },
    });
  }

  /** Open the edit flow (reuses the create page in edit mode). */
  editStyle(): void {
    const slug = this.style?.slug;
    if (!slug) {
      return;
    }
    this.router.navigate(['/', 'style-edit', slug], { state: { style: this.style } });
  }

  /** Confirm, then soft-delete this look (owner only; server re-checks). */
  async deleteStyle(): Promise<void> {
    const id = this.style?.id;
    if (!id || this.isDeleting) {
      return;
    }
    const alert = await this.alertCtrl.create({
      header: this.i18n.t('style_delete_title'),
      message: this.i18n.t('style_delete_message'),
      buttons: [
        { text: this.i18n.t('style_delete_cancel'), role: 'cancel' },
        {
          text: this.i18n.t('style_delete_confirm'),
          role: 'destructive',
          handler: () => { this.performDelete(id); },
        },
      ],
    });
    await alert.present();
  }

  private performDelete(id: number): void {
    this.isDeleting = true;
    this.cdr.markForCheck();
    this.networkAdapter.delete_v3('DELETE /me/styles/:id', {
      pathParams: { id: String(id) },
      authToken: this.single_user.token,
    }).subscribe({
      next: () => {
        // delete_v3 resolves next() only on a 2xx (incl. 204 No Content).
        this.isDeleting = false;
        this.success_notification(this.i18n.t('style_deleted'));
        this.router.navigate(['/', 'styles']);
        this.cdr.markForCheck();
      },
      error: () => {
        this.isDeleting = false;
        this.error_notification(this.i18n.t('style_delete_failed'));
        this.cdr.markForCheck();
      },
    });
  }

  // ── Restyle with Ain ──────────────────────────────────────────────────

  canRestyle(): boolean {
    return !!this.single_user.token && (this.style?.products?.length ?? 0) > 0;
  }

  useRestyleSuggestion(key: string): void {
    this.restyleInstruction = this.i18n.t(key);
  }

  runRestyle(): void {
    const slug = this.style?.slug;
    const instruction = this.restyleInstruction.trim();
    if (!slug || instruction === '' || this.restyleLoading || !this.single_user.token) {
      return;
    }
    this.restyleLoading = true;
    this.restylePreview = null;
    this.beacon('style_ai_used');
    this.cdr.markForCheck();

    const body = {
      style_slug: slug,
      instruction,
      locale: this.i18n.lang,
      session_id: this.ainSessionId,
      channel: 'MOBILE',
    };
    this.networkAdapter.post_v3('POST /ai/styles/restyle', body, { authToken: this.single_user.token }).subscribe({
      next: (res: any) => {
        this.restyleLoading = false;
        if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
          this.restylePreview = {
            cards: Array.isArray(res.data.products) ? res.data.products : [],
            rationale: res.data.rationale ?? '',
            interactionId: res.data.interaction_id ?? null,
          };
        } else {
          this.restylePreview = { cards: [], rationale: '', interactionId: null };
        }
        this.cdr.markForCheck();
      },
      error: () => {
        this.restyleLoading = false;
        this.restylePreview = { cards: [], rationale: '', interactionId: null };
        this.cdr.markForCheck();
      },
    });
  }

  saveRestyle(): void {
    const preview = this.restylePreview;
    if (!preview || preview.cards.length === 0 || this.restyleSaving || !this.single_user.token || !this.style) {
      return;
    }
    this.restyleSaving = true;
    this.cdr.markForCheck();

    const body = {
      name: this.style.style_name + ' — restyled',
      products: preview.cards.map((c) => c.id),
      source: 'ai',
      prompt: this.restyleInstruction.trim(),
      rationale: preview.rationale,
    };
    this.networkAdapter.post_v3('POST /me/styles', body, { authToken: this.single_user.token }).subscribe({
      next: (res: any) => {
        this.restyleSaving = false;
        if ((res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success') {
          this.beacon('style_saved', preview.interactionId ? { interaction_id: preview.interactionId } : {});
          this.toast.success(this.i18n.t('style_restyle_saved'), { position: 'top-center' });
          this.restylePreview = null;
          const slug = res.data?.slug ?? res.data?.data?.slug;
          if (slug) {
            this.router.navigate(['/', 'styles', slug]);
          }
        } else {
          this.toast.error(this.i18n.t('style_restyle_error'), { position: 'top-center' });
        }
        this.cdr.markForCheck();
      },
      error: () => {
        this.restyleSaving = false;
        this.toast.error(this.i18n.t('style_restyle_error'), { position: 'top-center' });
        this.cdr.markForCheck();
      },
    });
  }

  openRestyled(card: any): void {
    this.router.navigate(['/', 'product'], { queryParams: { id: card.id, name: card.name } });
  }

  private beacon(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.ainSessionId, ...extra };
      const opts = this.single_user.token ? { authToken: this.single_user.token } : {};
      this.networkAdapter.post_v3('POST /ai/events', body, opts).subscribe({ next: () => {}, error: () => {} });
    } catch {
      // analytics must never break the page
    }
  }

  private async ensureAinSession(): Promise<void> {
    const got = await Preferences.get({ key: 'ain_session' });
    if (got.value) {
      this.ainSessionId = got.value;
      return;
    }
    const id =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : 'm-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
    await Preferences.set({ key: 'ain_session', value: id });
    this.ainSessionId = id;
  }

  /**
   * Re-fetch a single style by slug for the deep-link / hard-reload path.
   * Uses the same response transform as the list, so `style` ends up in the
   * identical legacy shape (products carry product_id = legacy id).
   */
  private loadStyleBySlug(slug: string) {
    this.ui_controls.is_loading = true;
    this.cdr.markForCheck();

    this.networkAdapter.get_v3('GET /mobile/style-detail', {
      pathParams: { slug },
      // Authenticated so the detailShape resolves is_owner for the viewer.
      authToken: this.single_user.token,
    }).subscribe({
      next: (response: any) => {
        if (
          response.response_code === 200 &&
          response.status === 'success' &&
          response.data &&
          response.data.id
        ) {
          this.style = response.data;
        } else {
          // Unknown / inactive style, bounce back to the hub.
          this.router.navigate(['/styles']);
        }
        this.ui_controls.is_loading = false;
        this.cdr.markForCheck();
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.router.navigate(['/styles']);
      }
    });
  }

  ngOnDestroy() {
    this.sub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
    } else {
      this.single_user = JSON.parse(ret.value);
    }
  }

  // ========================================
  // Image Loading Handlers
  // ========================================

  onImageLoad(productId: number) {
    this.imageLoaded[productId] = true;
    this.cdr.markForCheck();
  }

  onImageError(productId: number) {
    this.imageLoaded[productId] = true; // Hide skeleton on error
    this.cdr.markForCheck();
  }

  // ========================================
  // Calculations
  // ========================================

  getTotal(): number {
    if (!this.style?.products) return 0;
    // item.price is a STRING post-transform (v3 DECIMAL preserved as text),
    // so coerce with Number(), otherwise reduce string-concatenates ("1020"
    // instead of 30) and the total renders garbage.
    return this.style.products.reduce((sum, item) => sum + Number(item.price), 0);
  }

  // ========================================
  // Wishlist / Closet
  // ========================================

  get_label() {
    this.ui_controls.is_loading_category = true;
    this.cdr.markForCheck();

    this.wishlistService.listLabels(this.single_user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  addToCloset(label: number) {
    this.ui_controls.is_loading_category = true;
    this.addCloset.label_id = label;
    this.isWishOpen = false;
    this.cdr.markForCheck();

    this.wishlistService.add(this.single_user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      })
      .catch(() => {
        this.ui_controls.is_loading_category = false;
        this.cdr?.markForCheck();
      });
  }

  startAddToCloset(productId: number, productName: string, image: string) {
    this.addCloset.id = this.single_user.id;
    this.addCloset.token = this.single_user.token;
    this.addCloset.product_id = productId;
    this.addCloset.product_name = productName;
    this.addCloset.product_image = image;
    this.rqst_param.id = this.single_user.id;
    this.rqst_param.token = this.single_user.token;
    this.get_label();
    this.isWishOpen = true;
  }

  OnDidDismiss() {
    this.isWishOpen = false;
  }

  // ========================================
  // Navigation
  // ========================================

  open_product(id: number, name: string) {
    this.router.navigate(['/', 'product'], { queryParams: { id, name } });
  }

  // ========================================
  // Add all to cart (buy the look) + share
  // ========================================

  /**
   * Buy the look: add every product in the style to the (multi-vendor)
   * cart, then go to the cart. Each add is independent, an out-of-stock or
   * unorderable piece is skipped (partial success), mirroring the AI Outfit
   * page's addLook(). Uses each product's v3 id (product_id post-transform).
   */
  async addAllToCart(): Promise<void> {
    const products = this.style?.products ?? [];
    if (products.length === 0 || this.isAddingLook) {
      return;
    }
    if (!this.single_user.token) {
      this.error_notification(this.i18n.t('sign_in_to_add_to_cart'));
      return;
    }
    this.isAddingLook = true;
    this.cdr.markForCheck();
    let added = 0;
    for (const item of products) {
      const body = {
        product_id: item.product_id,
        quantity: 1,
        size: '',
        color: '',
        is_custom: false,
        measurement: null,
        extra_measurement: null,
        note: null,
      };
      try {
        const res: any = await firstValueFrom(
          this.networkAdapter.post_v3('POST /cart/items', body, { authToken: this.single_user.token }),
        );
        const ok = res?.response_code === 200 && (res?.status === 'success' || (res?.data && res.data.success === true));
        if (ok) {
          added++;
        }
      } catch {
        // Skip a piece that can't be added; keep going.
      }
    }
    this.isAddingLook = false;
    this.cdr.markForCheck();
    if (added > 0) {
      this.success_notification(this.i18n.t('style_added_to_cart', { count: added }));
      this.router.navigate(['/', 'cart']);
    } else {
      this.error_notification(this.i18n.t('style_add_failed'));
    }
  }

  /**
   * Share this look via the native share sheet (Web Share API), falling
   * back to copying the storefront link. No @capacitor/share dependency, so
   * this ships over OTA. Mirrors the gift-card-detail share.
   */
  async shareStyle(): Promise<void> {
    const slug = this.style?.slug;
    if (!slug) {
      return;
    }
    const name = this.style?.style_name ?? '';
    const url = `${this.storefrontBase()}/styles/${slug}`;
    try {
      if ((navigator as any).share) {
        await (navigator as any).share({ title: name || '3bayti', text: name, url });
      } else {
        await navigator.clipboard.writeText(url);
        this.success_notification(this.i18n.t('style_link_copied'));
      }
    } catch {
      // User dismissed the share sheet; no-op.
    }
  }

  /** Web storefront origin, derived from the API base (api.<host> -> <host>). */
  private storefrontBase(): string {
    try {
      const u = new URL(GlobalComponent.baseURL);
      return `${u.protocol}//${u.host.replace(/^api\./, '')}`;
    } catch {
      return 'https://3bayti.ae';
    }
  }

  triggerBack() {
    this.nav.back();
  }

  // ========================================
  // Notifications
  // ========================================

  error_notification(message: string) {
    this.toast.error(message, { position: "top-center" });
  }

  /**
   * Inline create from the wishlist sheet: POST the new label, then drop the
   * pending product straight into it (addToCloset closes the sheet + toasts).
   * On failure the sheet stays open so the user can retry.
   */
  async onCreateLabel(name: string): Promise<void> {
    if (this.isCreatingLabel) {
      return;
    }
    this.isCreatingLabel = true;
    this.cdr.markForCheck();
    try {
      const label = await this.wishlistService.createLabel(this.single_user.token, name);
      if (label) {
        this.addToCloset(label.id);
      } else {
        this.error_notification(this.i18n.t('network_error_retry'));
      }
    } catch (err) {
      this.error_notification(apiErrorMessage(err, this.i18n.t('network_error_retry')));
    } finally {
      this.isCreatingLabel = false;
      this.cdr.markForCheck();
    }
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }
}
