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
import { Subscription } from 'rxjs';
import { Preferences } from '@capacitor/preferences';

import { GlobalComponent } from '../../global-component';
import { I18nService } from '../../i18n.service';
import { TranslatePipe } from '../../translate.pipe';
import {NetworkService} from "../../service/network.service";

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

    const sub = this.networkService.post_request(
      { id: this.userId, token: this.userToken },
      GlobalComponent.vendor_dashboard
    ).subscribe({
      next: (response) => {
        if (response.status === 'success') {
          this.storeData = response.data.store;
          this.stats = {
            ...this.stats,
            ...response.data.stats
          };
        }
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

  editStoreProfile(): void {
    this.router.navigate(['/vendor-store-profile']);
  }

  openSettings(): void {
    this.router.navigate(['/vendor-settings']);
  }

  async toggleStoreStatus(event: CustomEvent): Promise<void> {
    const newStatus = event.detail.checked;

    // Show confirmation dialog
    const alert = await this.alertCtrl.create({
      header: newStatus ? 'Activate Store' : 'Deactivate Store',
      message: newStatus
        ? 'Your store will be visible to customers. Are you sure?'
        : 'Your store will be hidden from customers. Existing orders will still be processed. Are you sure?',
      buttons: [
        {
          text: 'Cancel',
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
          text: 'Confirm',
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

    const sub = this.networkService.post_request(
      {
        id: this.userId,
        token: this.userToken,
        is_active: isActive ? 1 : 0
      },
      GlobalComponent.vendor_toggle_status
    ).subscribe({
      next: async (response) => {
        if (response.status === 'success') {
          if (this.storeData) {
            this.storeData.is_active = isActive;
          }
          const toast = await this.toastCtrl.create({
            message: isActive ? 'Store is now active' : 'Store is now hidden',
            duration: 2000,
            position: 'bottom',
            color: isActive ? 'success' : 'warning'
          });
          await toast.present();
        } else {
          // Revert on failure
          if (this.storeData) {
            this.storeData.is_active = !isActive;
          }
          this.showErrorToast('Failed to update store status');
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
