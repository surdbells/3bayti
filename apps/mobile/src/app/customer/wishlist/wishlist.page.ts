import { Component, HostListener, OnDestroy, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonButton,
  IonButtons,
  IonCol,
  IonContent,
  IonGrid,
  IonHeader,
  IonRow,
  IonTitle,
  IonToolbar,
  NavController,
  Platform,
} from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';
import { ConnectionService } from '../../service/connection.service';
import { Router, RouterLink } from '@angular/router';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { Preferences } from '@capacitor/preferences';
import { Labels } from '../../class/labels';
import { Wishlist } from '../../class/wishlist';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import { WishlistService, WishlistProduct } from '../../core/services/wishlist.service';

import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
import { AxBottomSheetComponent } from '../../shared/ax-mobile/bottom-sheet';

/**
 * Wishlist ("My Closet") — M3.2.Z.3-Mobile.
 *
 * Migrated off the legacy GlobalComponent.readWishlist /
 * addWishlistLabel / readWishlistLabel "closets" endpoints onto the v3
 * label-aware wishlist (WishlistService → /v3/me/wishlist).
 *
 * UX preserved: a horizontal row of labels (with an "Add" card and an
 * "All saved" card), and a product grid for the selected label. label
 * id 0 = the "All saved" view (all saved products, regardless of
 * label).
 */
@Component({
  selector: 'app-wishlist',
  templateUrl: './wishlist.page.html',
  styleUrls: ['./wishlist.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    FormsModule,
    IonButtons,
    RouterLink,
    IonButton,
    IonCol,
    IonRow,
    IonGrid,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    AxTextFieldComponent,
    AxBottomSheetComponent,
    AppTabBarComponent,
  ],
})
export class WishlistPage implements OnInit, OnDestroy {
  wishlists: Wishlist[] = [];
  categories: Labels[] = [];
  /** Tracks per-item image load state for the m6d skeleton overlay. */
  imageLoaded: { [key: number]: boolean } = {};
  isOnline = true;
  isAddLabelOpen = false;
  private sub: Subscription;
  private backSub?: Subscription;

  constructor(
    private nav: NavController,
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private toast: AxNotificationService,
    private i18n: I18nService,
    private wishlistService: WishlistService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => (this.isOnline = v));
  }

  @HostListener('window:ionBackButton', ['$event'])
  onHardwareBack(ev: Event) {
    (ev as CustomEvent).detail.register(100, () => {
      this.nav.navigateRoot('/account');
    });
  }

  ui_controls = {
    is_empty: false,
    is_loading: false,
    is_creating: false,
    is_deleting: false,
  };

  /** Currently selected label name. Empty = the "All saved" view. */
  selected_label = '';
  /** Currently selected label id. 0 = "All saved" (no label filter). */
  selected_label_id = 0;

  add_label_name = '';

  single_user = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_customer: false,
  };

  ngOnInit() {
    // Loaded in ionViewWillEnter so the wishlist refreshes on every entry
    // (Ionic caches the page, so ngOnInit runs only once — otherwise labels
    // and items show a stale/empty snapshot after adding elsewhere and
    // returning).
  }

  ionViewWillEnter() {
    this.getObject();
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }

  ionViewDidEnter() {
    this.backSub = this.platform.backButton.subscribeWithPriority(9999, () => {
      this.nav.navigateRoot('/settings');
    });
  }

  ionViewWillLeave() {
    this.backSub?.unsubscribe();
  }

  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.single_user = JSON.parse(ret.value);
    await this.loadLabels();
    // Default to the "All saved" view on entry.
    await this.selectLabel(0, this.i18n.t('text_all_saved'));
  }

  /** Map a v3 saved product into the template's Wishlist shape. */
  private toWishlist(p: WishlistProduct): Wishlist {
    const image = p.primary_image?.url ?? '';
    return new Wishlist(p.id, p.id, p.name, image);
  }

  async loadLabels() {
    this.ui_controls.is_loading = true;
    try {
      const labels = await this.wishlistService.listLabels(this.single_user.token);
      this.categories = labels.map(l => new Labels(l.id, l.name, l.count));
    } catch {
      this.categories = [];
    } finally {
      this.ui_controls.is_loading = false;
    }
  }

  /**
   * Load the products for a label. id 0 = "All saved" (no filter).
   */
  async selectLabel(labelId: number, name: string) {
    this.selected_label_id = labelId;
    this.selected_label = name || this.i18n.t('text_all_saved');
    this.ui_controls.is_empty = false;
    this.ui_controls.is_loading = true;
    this.wishlists = [];
    this.imageLoaded = {};
    try {
      const page = await this.wishlistService.listProducts(this.single_user.token, {
        labelId: labelId > 0 ? labelId : undefined,
      });
      this.wishlists = page.products.map(p => this.toWishlist(p));
      this.ui_controls.is_empty = this.wishlists.length === 0;
    } catch {
      this.ui_controls.is_empty = true;
    } finally {
      this.ui_controls.is_loading = false;
    }
  }

  /** Template alias retained for the existing markup. */
  get_wishlist_by_label(label: number, name: string) {
    void this.selectLabel(label, name);
  }

  async add_wishlist_label() {
    const name = this.add_label_name.trim();
    if (name.length === 0) {
      this.error_notification(this.i18n.t('text_name_required'));
      return;
    }
    this.ui_controls.is_creating = true;
    try {
      const created = await this.wishlistService.createLabel(this.single_user.token, name);
      if (created) {
        this.add_label_name = '';
        this.cancel();
        await this.loadLabels();
        this.success_notification(this.i18n.t('text_category_created'));
      } else {
        this.error_notification(this.i18n.t('text_something_went_wrong'));
      }
    } catch {
      this.error_notification(this.i18n.t('text_something_went_wrong'));
    } finally {
      this.ui_controls.is_creating = false;
    }
  }

  /** Remove a saved product from the wishlist + refresh the view. */
  async remove_item(productId: number) {
    try {
      const ok = await this.wishlistService.remove(this.single_user.token, productId);
      if (ok) {
        this.wishlists = this.wishlists.filter(w => w.product !== productId);
        this.ui_controls.is_empty = this.wishlists.length === 0;
        await this.loadLabels();
      }
    } catch {
      this.error_notification(this.i18n.t('text_something_went_wrong'));
    }
  }

  cancel() {
    this.isAddLabelOpen = false;
  }

  error_notification(message: string) {
    this.toast.error(message, { position: 'top-center' });
  }

  success_notification(message: string) {
    this.toast.success(message, { position: 'top-center' });
  }

  open_product(id: number, name: string) {
    this.router.navigate(['/', 'product'], { queryParams: { id, name } });
  }

  onImageLoad(itemId: number): void {
    this.imageLoaded[itemId] = true;
  }

  onImageError(itemId: number): void {
    this.imageLoaded[itemId] = true;
  }
}
