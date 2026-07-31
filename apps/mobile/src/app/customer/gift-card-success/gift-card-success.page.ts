import { Component, OnInit } from '@angular/core';
import { DatePipe, DecimalPipe } from '@angular/common';
import { IonContent, IonFooter } from '@ionic/angular/standalone';
import { ActivatedRoute, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';

/**
 * Post-payment success screen for a GIFT CARD purchase.
 *
 * A gift-card order is a synthetic order with no line items and no vendor, so
 * the product success screen (with its "view order" / "message vendor"
 * actions) doesn't fit. This screen confirms the payment and shows the card
 * the buyer just funded: value, code, recipient and expiry — plus whether we
 * will deliver it or they should share the code themselves.
 *
 * The card is passed via router state when the caller already has it;
 * otherwise it is fetched by id from GET /gift-cards/mine. A failed fetch is
 * NOT an error state — payment already succeeded, so we still confirm and
 * point the buyer to their wallet.
 */
@Component({
  selector: 'app-gift-card-success',
  templateUrl: './gift-card-success.page.html',
  styleUrls: ['./gift-card-success.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonFooter,
    TranslatePipe,
    AxIconComponent,
    AxLoaderComponent,
    DecimalPipe,
    DatePipe,
  ],
})
export class GiftCardSuccessPage implements OnInit {
  card: any = null;
  isLoading = true;

  private token = '';
  private cardId = 0;

  constructor(
    private route: ActivatedRoute,
    private router: Router,
    private adapter: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
  ) {}

  async ngOnInit(): Promise<void> {
    // Prefer the card handed over in navigation state (no round-trip).
    const nav = this.router.getCurrentNavigation() ?? (this.router as any).lastSuccessfulNavigation;
    this.card = nav?.extras?.state?.['card'] ?? history.state?.card ?? null;

    this.cardId = Number(this.route.snapshot.queryParamMap.get('cardId') || 0);

    if (this.card) {
      this.isLoading = false;
      return;
    }
    if (this.cardId <= 0) {
      this.isLoading = false;
      return;
    }

    const stored: any = await Preferences.get({ key: 'user' });
    this.token = stored.value ? (JSON.parse(stored.value)?.token ?? '') : '';
    if (!this.token) {
      this.isLoading = false;
      return;
    }
    this.loadCard();
  }

  private loadCard(): void {
    this.adapter.get_v3('GET /gift-cards/mine', { authToken: this.token }).subscribe({
      next: (res: any) => {
        const list: any[] = Array.isArray(res?.data) ? res.data : [];
        this.card = list.find((c) => Number(c?.id) === this.cardId) ?? null;
        this.isLoading = false;
      },
      error: () => { this.isLoading = false; },
    });
  }

  /** Theme accent for the card panel, falling back to the brand gold. */
  get accent(): string {
    return this.card?.theme_meta?.accent_color || '#B9935A';
  }

  get themeLabel(): string {
    return this.card?.theme_meta?.label ?? '';
  }

  /** True when we auto-deliver to a recipient (vs the buyer sharing the code). */
  get autoDelivered(): boolean {
    return !!(this.card?.recipient_email || this.card?.recipient_phone);
  }

  async copyCode(): Promise<void> {
    const code = this.card?.code;
    if (!code) return;
    try {
      await navigator.clipboard.writeText(code);
      this.notify.success(this.i18n.t('gcs_code_copied'));
    } catch {
      /* Clipboard unavailable (older webview) — the code is on screen anyway. */
    }
  }

  goToWallet(): void {
    this.router.navigate(['/my-gift-cards'], { replaceUrl: true });
  }

  goHome(): void {
    this.router.navigate(['/account'], { replaceUrl: true });
  }
}
