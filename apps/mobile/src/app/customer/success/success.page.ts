import { Component, OnInit } from '@angular/core';
import { DecimalPipe } from '@angular/common';
import {
  IonContent,
  IonFooter,
} from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { BlockerService } from '../../blocker.service';

/** One purchased line on the receipt. */
interface SuccessItem {
  product_name: string;
  product_image: string | null;
  quantity: number;
  unit_price: number;
  subtotal: number;
  size: string | null;
  color: string | null;
}

/** The receipt shown on the success screen. */
interface SuccessOrder {
  id: number;
  order_reference: string;
  date: string;
  subtotal: number;
  delivery_fee: number;
  discount: number;
  gift_card_amount: number;
  total: number;
  currency: string;
  items: SuccessItem[];
  recipient: string | null;
  address_lines: string[];
}

/**
 * Post-payment success screen (product orders).
 *
 * Reached from /process once the checkout poll reports a terminal PAID
 * state, and from checkout directly on the gift-card full-cover path
 * (gateway skipped). It confirms the payment and renders the full order
 * receipt — items, money breakdown and delivery address — so the customer
 * sees exactly what they bought without opening My Orders.
 *
 * The order is fetched by id when /process passes one; otherwise the id is
 * resolved from the checkout-status endpoint using the order reference. A
 * failed lookup is NOT an error state: payment already succeeded, so the
 * screen still confirms success and simply omits the detail block.
 *
 * Gift-card purchases use their own screen (/gift-card-success).
 */
@Component({
  selector: 'app-success',
  templateUrl: './success.page.html',
  styleUrls: ['./success.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonFooter,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    DecimalPipe,
  ],
})
export class SuccessPage implements OnInit {
  orderReference = '';
  giftCardPaid = false;

  order: SuccessOrder | null = null;
  isLoading = true;

  private token = '';
  private orderId: number | null = null;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private adapter: MobileNetworkAdapter,
    private blocker: BlockerService,
  ) {}

  ionViewDidEnter(): void {
    // Block hardware back + edge-swipe while the confirmation is shown so the
    // customer can't reverse into the completed checkout. They move on via the
    // explicit "View order" / "Continue shopping" buttons.
    this.blocker.block({ disableSwipe: true, disableHardwareBack: true });
  }

  ionViewWillLeave(): void {
    this.blocker.unblock();
  }

  async ngOnInit(): Promise<void> {
    const params = this.route.snapshot.queryParamMap;
    this.orderReference = params.get('orderReference') || '';
    this.giftCardPaid =
      params.get('gift_card_paid') === 'true' || params.get('gift_card_paid') === '1';

    const idParam = Number(params.get('orderId') || 0);
    this.orderId = idParam > 0 ? idParam : null;

    const stored: any = await Preferences.get({ key: 'user' });
    this.token = stored.value ? (JSON.parse(stored.value)?.token ?? '') : '';

    if (!this.token || !this.orderReference) {
      this.isLoading = false;
      return;
    }

    if (this.orderId !== null) {
      this.loadOrder(this.orderId);
    } else {
      this.resolveIdThenLoad();
    }
  }

  /** No id passed — ask the status endpoint for it (keyed by reference). */
  private resolveIdThenLoad(): void {
    this.adapter
      .get_v3('GET /checkout/status/:order_reference', {
        authToken: this.token,
        pathParams: { order_reference: this.orderReference },
      })
      .subscribe({
        next: (res: any) => {
          const id = Number(res?.data?.order_id ?? 0);
          if (id > 0) {
            this.loadOrder(id);
          } else {
            this.isLoading = false;
          }
        },
        error: () => { this.isLoading = false; },
      });
  }

  private loadOrder(id: number): void {
    this.adapter
      .get_v3('GET /orders/:id', {
        authToken: this.token,
        pathParams: { id: String(id) },
      })
      .subscribe({
        next: (res: any) => {
          const o = res?.data?.order;
          if (o) { this.order = this.mapOrder(o); }
          this.isLoading = false;
        },
        // Payment already succeeded — a detail-fetch failure must never turn
        // this into an error screen. Show the confirmation without it.
        error: () => { this.isLoading = false; },
      });
  }

  private mapOrder(o: any): SuccessOrder {
    const ship = o.shipping_address ?? o.billing_address ?? null;
    const recipient = ship
      ? [ship.first_name, ship.last_name].filter(Boolean).join(' ').trim()
      : '';
    const addressLines: string[] = ship
      ? [
          ship.phone,
          ship.street,
          [ship.city, ship.state_province].filter(Boolean).join(', '),
          ship.country_code,
        ].filter((l: any): l is string => typeof l === 'string' && l.trim() !== '')
      : [];

    return {
      id: Number(o.id ?? 0),
      order_reference: o.order_reference ?? this.orderReference,
      date: o.date ?? '',
      subtotal: Number(o.subtotal ?? 0),
      delivery_fee: Number(o.delivery_fee ?? 0),
      discount: Number(o.discount ?? 0),
      gift_card_amount: Number(o.gift_card_amount ?? 0),
      total: Number(o.total ?? 0),
      currency: o.currency ?? 'AED',
      items: Array.isArray(o.items)
        ? o.items.map((it: any): SuccessItem => ({
            product_name: it.product_name ?? '',
            product_image: it.product_image ?? null,
            quantity: Number(it.quantity ?? 0),
            unit_price: Number(it.unit_price ?? 0),
            subtotal: Number(it.subtotal ?? 0),
            size: it.size ?? null,
            color: it.color ?? null,
          }))
        : [],
      recipient: recipient !== '' ? recipient : null,
      address_lines: addressLines,
    };
  }

  /** Variant meta line for an item ("M · Black"). */
  variantLine(item: SuccessItem): string {
    return [item.size, item.color].filter(Boolean).join(' · ');
  }

  goToOrder(): void {
    // The order-detail route is 'orders/:id' (NOT 'order-detail/:id') — the
    // wrong path matched nothing, so the button silently did nothing.
    if (this.order?.id) {
      this.router.navigate(['/', 'orders', this.order.id]);
    } else {
      this.router.navigate(['/my-orders']);
    }
  }

  goHome(): void {
    this.router.navigate(['/account']);
  }
}
