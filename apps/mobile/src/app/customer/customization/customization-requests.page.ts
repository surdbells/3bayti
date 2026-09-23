import { Component, ChangeDetectionStrategy, ChangeDetectorRef, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import {
  IonButton,
  IonButtons,
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { firstValueFrom } from 'rxjs';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AppTabBarComponent } from '../../shared/app-tab-bar';
import { CustomizationPaymentService } from './customization-payment.service';

/** A customization request (v3 CustomizationRequestSerializer::customerShape). */
interface CustomizationRequestItem {
  id: number;
  status: string;
  product: { slug: string; name: string; primary_image: { url: string } | null };
  vendor: { id: number; name: string; slug: string };
  customer_notes: string;
  quote: { amount: string; currency: string | null; lead_time_days: number | null; vendor_notes: string | null } | null;
  is_terminal: boolean;
}

/**
 * /customization-requests, the customer's bespoke-customization requests (P5).
 *
 * Auth-only. Lists each request with the vendor's quote and the contextual
 * action: accept/reject a quote, cancel a pending/quoted request, or pay an
 * accepted quote. Mirrors the web account-customization page.
 */
@Component({
  selector: 'app-customization-requests',
  templateUrl: './customization-requests.page.html',
  styleUrls: ['./customization-requests.page.scss'],
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [
    CommonModule,
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButtons,
    IonButton,
    TranslatePipe,
    AxIconComponent,
    AppTabBarComponent,
  ],
})
export class CustomizationRequestsPage implements OnInit {
  requests: CustomizationRequestItem[] = [];
  isLoading = false;
  hasLoaded = false;

  private busy = new Set<number>();
  private token = '';

  constructor(
    private router: Router,
    private nav: NavController,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
    private payment: CustomizationPaymentService,
    private cdr: ChangeDetectorRef,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret: any = await Preferences.get({ key: 'user' });
    if (!ret.value) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.token = JSON.parse(ret.value).token ?? '';
    this.load();
  }

  private load(): void {
    this.isLoading = true;
    this.cdr.markForCheck();
    this.networkAdapter.get_v3('GET /me/customization-requests', {
      authToken: this.token,
      queryParams: { limit: 50, offset: 0 },
    }).subscribe({
      next: (res: any) => {
        this.requests = (res?.response_code === 200 && res?.status === 'success' && Array.isArray(res.data))
          ? res.data
          : [];
        this.isLoading = false;
        this.hasLoaded = true;
        this.cdr.markForCheck();
      },
      error: () => {
        this.isLoading = false;
        this.hasLoaded = true;
        this.cdr.markForCheck();
      },
    });
  }

  isBusy(id: number): boolean {
    return this.busy.has(id);
  }

  accept(req: CustomizationRequestItem): void {
    void this.act(req, 'POST /me/customization-requests/:id/accept');
  }

  reject(req: CustomizationRequestItem): void {
    void this.act(req, 'POST /me/customization-requests/:id/reject');
  }

  cancel(req: CustomizationRequestItem): void {
    void this.act(req, 'POST /me/customization-requests/:id/cancel');
  }

  private async act(req: CustomizationRequestItem, routeKey: string): Promise<void> {
    if (this.busy.has(req.id)) return;
    this.busy.add(req.id);
    this.cdr.markForCheck();
    try {
      const res: any = await firstValueFrom(
        this.networkAdapter.post_v3(routeKey, {}, {
          authToken: this.token,
          pathParams: { id: String(req.id) },
        }),
      );
      if (res?.status === 'error' || !res?.data) {
        this.toast.error(res?.message || this.i18n.t('cust_action_failed'), { position: 'top-center' });
      } else {
        this.requests = this.requests.map((r) => (r.id === req.id ? res.data : r));
      }
    } catch {
      this.toast.error(this.i18n.t('cust_action_failed'), { position: 'top-center' });
    } finally {
      this.busy.delete(req.id);
      this.cdr.markForCheck();
    }
  }

  pay(req: CustomizationRequestItem): void {
    if (this.busy.has(req.id)) return;
    this.busy.add(req.id);
    this.cdr.markForCheck();
    this.payment.pay(req.id, this.token, {
      onPaid: () => {
        this.busy.delete(req.id);
        this.load();
      },
      onFailed: () => {
        this.busy.delete(req.id);
        this.cdr.markForCheck();
      },
    });
  }

  browse(): void {
    this.router.navigate(['/', 'explore']);
  }

  openProduct(req: CustomizationRequestItem): void {
    this.router.navigate(['/', 'product'], { queryParams: { slug: req.product.slug, name: req.product.name } });
  }

  statusLabel(status: string): string {
    return this.i18n.t('cust_status_' + status);
  }

  triggerBack(): void {
    this.nav.back();
  }
}
