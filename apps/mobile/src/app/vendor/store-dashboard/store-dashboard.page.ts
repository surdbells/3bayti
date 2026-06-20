import {
  Component,
  OnInit,
  OnDestroy,
  ChangeDetectorRef,
  ChangeDetectionStrategy
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonContent,
  IonHeader,
  IonToolbar,
  IonTitle,
  IonButton,
  IonButtons,
  IonRefresher,
  IonRefresherContent,
  IonToggle,
  NavController,
  AlertController,
  ToastController
} from '@ionic/angular/standalone';
import { Subscription, forkJoin, of, catchError } from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import {NetworkService} from "../../service/network.service";
import {MobileNetworkAdapter} from "../../core/http/mobile-network-adapter";

import { AxIconComponent } from '../../shared/ax-mobile/icon';
export interface StoreData {
  store_id: number;
  name: string;
  logo: string | null;
  banner: string | null;
  description: string | null;
  is_active: boolean;
  rating: number | null;
  review_count: number;
  created_at: string;
}

export interface StoreStats {
  pending_orders: number;
  processing_orders: number;
  completed_orders: number;
  total_orders: number;
  unread_messages: number;
  total_products: number;
  active_products: number;
  out_of_stock: number;
  low_stock_items: number;
  monthly_earnings: number;
  total_earnings: number;
  pending_payout: number;
  pending_reviews: number;
}

@Component({
  selector: 'app-store-dashboard',
  templateUrl: './store-dashboard.page.html',
  styleUrls: ['./store-dashboard.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    IonContent,
    IonHeader,
    IonToolbar,
    IonTitle,
    IonButton,
    IonButtons,
    IonRefresher,
    IonRefresherContent,
    IonToggle,
    TranslatePipe,
    AxIconComponent,
  ]
})
export class StoreDashboardPage implements OnInit, OnDestroy {
  storeData: StoreData | null = null;
  stats: StoreStats = {
    pending_orders: 0,
    processing_orders: 0,
    completed_orders: 0,
    total_orders: 0,
    unread_messages: 0,
    total_products: 0,
    active_products: 0,
    out_of_stock: 0,
    low_stock_items: 0,
    monthly_earnings: 0,
    total_earnings: 0,
    pending_payout: 0,
    pending_reviews: 0
  };

  isLoading = true;
  isTogglingStatus = false;
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
    is_store_active: false,
    is_store_approved: false,
    is_customer: false
  }
  private userId = 0;
  private userToken = '';
  private subscriptions: Subscription[] = [];

  constructor(
    private networkService: NetworkService,
    private networkAdapter: MobileNetworkAdapter,
    private router: Router,
    private nav: NavController,
    private alertCtrl: AlertController,
    private toastCtrl: ToastController,
    private cdr: ChangeDetectorRef,
    private i18n: I18nService
  ) {}

  ngOnInit(): void {
    this.getObject();
  }
  async getObject() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret.value == null){
      this.router.navigate(['/', 'login']);
      return;
    }else{
      this.single_user = JSON.parse(ret.value);
      this.loadUserAndDashboard();
    }
  }
  ngOnDestroy(): void {
    this.subscriptions.forEach(sub => sub.unsubscribe());
  }

  async loadUserAndDashboard(): Promise<void> {
    // Check if user is a vendor
    this.userId = this.single_user.id;
    this.userToken = this.single_user.token;
    if (!this.single_user.is_vendor) {
      this.router.navigate(['/account']);
      return;
    }

    this.loadDashboardData();
  }

  loadDashboardData(): void {
    this.isLoading = true;
    this.cdr.markForCheck();

    // v3 split: the dashboard calculator (GET /vendor/dashboard) returns the
    // aggregated stats (catalog/sales/operations), but NOT store identity or
    // the active flag. The store profile (GET /vendor/store) carries
    // name/logo/is_active. Fetch both in parallel and merge.
    const sub = forkJoin({
      dashboard: this.networkAdapter.get_v3('GET /vendor/dashboard', {
        authToken: this.userToken,
      }),
      store: this.networkAdapter.get_v3('GET /vendor/store', {
        authToken: this.userToken,
      }),
      // Unread chat count — separate endpoint (the dashboard calculator
      // doesn't include it). Resilient: a chat failure must not blank the
      // whole dashboard, so swallow errors to a null result here.
      unread: this.networkAdapter.get_v3('GET /vendor/chat/unread-count', {
        authToken: this.userToken,
      }).pipe(catchError(() => of(null))),
    }).subscribe({
      next: ({ dashboard, store, unread }) => {
        const dRes = dashboard as { status?: string; data?: any };
        const sRes = store as { status?: string; data?: any };

        // --- Store identity + active flag (GET /vendor/store, adminShape) ---
        if (sRes?.status === 'success' && sRes.data) {
          const v = sRes.data;
          this.storeData = {
            store_id: v.id,
            name: v.name,
            logo: v.logo_url ?? null,
            banner: v.cover_image_url ?? null,
            description: v.description ?? null,
            is_active: !!v.is_active,
            rating: null,
            review_count: 0,
            created_at: v.created_at,
          };
        }

        // --- Aggregated stats (GET /vendor/dashboard, compute()) ----------
        if (dRes?.status === 'success' && dRes.data) {
          const d = dRes.data;
          const catalog = d.catalog ?? {};
          const sales = d.sales ?? {};
          const ops = d.operations ?? {};
          this.stats = {
            ...this.stats,
            // Catalog health
            total_products: catalog.total_products ?? 0,
            active_products: catalog.active ?? 0,
            out_of_stock: catalog.out_of_stock ?? 0,
            low_stock_items: catalog.low_stock ?? 0,
            // Sales — period revenue is the 30-day default window = "this month"
            monthly_earnings: sales.revenue ?? 0,
            total_earnings: sales.all_time_revenue ?? 0,
            completed_orders: sales.orders ?? 0,
            total_orders: sales.all_time_orders ?? 0,
            // Operations queue (order fulfilment)
            pending_orders: ops.awaiting_acceptance ?? 0,
            processing_orders: ops.to_ship ?? 0,
            // Not provided by the v3 dashboard calculator; left at default 0.
            // (pending_payout, pending_reviews)
          };
        }

        // --- Unread chat count (GET /vendor/chat/unread-count) ------------
        // Mapped separately from the dashboard stats (different endpoint);
        // null when the call failed (catchError above) -> default 0.
        const uRes = unread as { status?: string; data?: { unread_count?: number } } | null;
        this.stats = {
          ...this.stats,
          unread_messages: uRes?.data?.unread_count ?? 0,
        };

        this.isLoading = false;
        this.cdr.markForCheck();
      },
      error: (err) => {
        console.error('Failed to load dashboard:', err);
        this.isLoading = false;
        this.cdr.markForCheck();
      }
    });

    this.subscriptions.push(sub);
  }

  navigateTo(path: string): void {
    this.router.navigate([path]);
  }

  /** These vendor screens aren't built yet — surface an honest toast instead
   *  of navigating to a non-existent route (which dead-ended on a blank page). */
  async comingSoon(): Promise<void> {
    const toast = await this.toastCtrl.create({
      message: this.i18n.t('store_dashboard_coming_soon'),
      duration: 2000,
      position: 'bottom',
      color: 'medium',
    });
    await toast.present();
  }

  editStoreProfile(): void {
    void this.comingSoon();
  }

  openSettings(): void {
    void this.comingSoon();
  }

  async toggleStoreStatus(event: CustomEvent): Promise<void> {
    const newStatus = event.detail.checked;

    // Show confirmation dialog
    const alert = await this.alertCtrl.create({
      header: newStatus
        ? this.i18n.t('store_dashboard_activate_store')
        : this.i18n.t('store_dashboard_deactivate_store'),
      message: newStatus
        ? this.i18n.t('store_dashboard_activate_confirm')
        : this.i18n.t('store_dashboard_deactivate_confirm'),
      buttons: [
        {
          text: this.i18n.t('cancel'),
          role: 'cancel',
          handler: () => {
            // Revert toggle
            if (this.storeData) {
              this.storeData.is_active = !newStatus;
              this.cdr.markForCheck();
            }
          }
        },
        {
          text: this.i18n.t('store_dashboard_confirm'),
          handler: () => {
            this.updateStoreStatus(newStatus);
          }
        }
      ]
    });

    await alert.present();
  }

  private updateStoreStatus(isActive: boolean): void {
    this.isTogglingStatus = true;
    this.cdr.markForCheck();

    const sub = this.networkAdapter.patch_v3(
      'PATCH /vendor/store/status',
      { store_status: isActive },
      { authToken: this.userToken }
    ).subscribe({
      next: async (response: any) => {
        if (response.status === 'success') {
          // The controller echoes the new state in data.store_status; trust
          // it over the optimistic value in case the server toggled instead.
          const confirmed =
            response.data && typeof response.data.store_status === 'boolean'
              ? response.data.store_status
              : isActive;
          if (this.storeData) {
            this.storeData.is_active = confirmed;
          }
          const toast = await this.toastCtrl.create({
            message: confirmed
              ? this.i18n.t('store_dashboard_store_now_active')
              : this.i18n.t('store_dashboard_store_now_hidden'),
            duration: 2000,
            position: 'bottom',
            color: confirmed ? 'success' : 'warning'
          });
          await toast.present();
        } else {
          // Revert on failure
          if (this.storeData) {
            this.storeData.is_active = !isActive;
          }
          this.showErrorToast(this.i18n.t('store_dashboard_status_update_failed'));
        }
        this.isTogglingStatus = false;
        this.cdr.markForCheck();
      },
      error: async () => {
        // Revert on error
        if (this.storeData) {
          this.storeData.is_active = !isActive;
        }
        this.showErrorToast('Failed to update store status');
        this.isTogglingStatus = false;
        this.cdr.markForCheck();
      }
    });

    this.subscriptions.push(sub);
  }

  async contactSupport(): Promise<void> {
    const alert = await this.alertCtrl.create({
      header: this.i18n.t('title_contact_support'),
      message: this.i18n.t('prompt_how_to_reach_us'),
      buttons: [
        {
          text: this.i18n.t('button_email'),
          handler: () => {
            window.open('mailto:info@3bayti.ae', '_system');
          }
        },
        {
          text: this.i18n.t('button_whatsapp'),
          handler: () => {
            window.open('https://wa.me/971504559975', '_system');
          }
        },
        {
          text: this.i18n.t('cancel'),
          role: 'cancel'
        }
      ]
    });

    await alert.present();
  }

  onLogoError(event: Event): void {
    const img = event.target as HTMLImageElement;
    img.style.display = 'none';
  }

  handleRefresh(event: CustomEvent): void {
    this.loadDashboardData();
    setTimeout(() => {
      (event.target as HTMLIonRefresherElement).complete();
    }, 500);
  }

  goBack(): void {
    this.nav.back();
  }

  private async showErrorToast(message: string): Promise<void> {
    const toast = await this.toastCtrl.create({
      message,
      duration: 3000,
      position: 'bottom',
      color: 'danger'
    });
    await toast.present();
  }
}
