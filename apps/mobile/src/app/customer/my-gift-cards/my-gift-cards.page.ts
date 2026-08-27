import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  IonRefresher, IonRefresherContent, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { apiErrorMessage } from '../../core/http/api-error';
import { Preferences } from '@capacitor/preferences';
import { GiftCardPaymentService } from '../gift-cards/gift-card-payment.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { GlobalComponent } from '../../global-component';
import { cfImage } from '../../shared/cf-image';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

/** UI buckets for the wallet status filter row. */
type GiftCardFilter = 'all' | 'active' | 'pending_payment' | 'used' | 'expired' | 'voided';

@Component({
  selector: 'app-my-gift-cards',
  templateUrl: './my-gift-cards.page.html',
  styleUrls: ['./my-gift-cards.page.scss'],
  standalone: true,
  imports: [
    CommonModule, IonHeader, IonToolbar, IonTitle, IonButtons, IonButton,
    IonContent, IonRefresher, IonRefresherContent,
    AxLoaderComponent, AxIconComponent, TranslatePipe,
  ],
})
export class MyGiftCardsPage implements OnInit {

  cards: any[] = [];
  ui = { loading: true };

  /**
   * Active wallet status filter. Each key maps a UI bucket to one or more raw
   * backend statuses (see statusMeta). 'all' shows everything.
   */
  filter: GiftCardFilter = 'all';

  // Filter buckets -> the raw card.status values they include.
  private readonly filterBuckets: Record<string, string[]> = {
    active:          ['active', 'partially_used'],
    pending_payment: ['pending_payment'],
    used:            ['exhausted'],
    expired:         ['expired'],
    voided:          ['voided'],
  };

  // Order + i18n keys for the segment/chip row.
  readonly filters: { key: GiftCardFilter; labelKey: string }[] = [
    { key: 'all',             labelKey: 'mgc_filter_all' },
    { key: 'active',          labelKey: 'mgc_filter_active' },
    { key: 'pending_payment', labelKey: 'mgc_filter_pending' },
    { key: 'used',            labelKey: 'mgc_filter_used' },
    { key: 'expired',         labelKey: 'mgc_filter_expired' },
    { key: 'voided',          labelKey: 'mgc_filter_voided' },
  ];

  /** Cards matching the active filter. */
  get filteredCards(): any[] {
    if (this.filter === 'all') return this.cards;
    const allowed = this.filterBuckets[this.filter] ?? [];
    return this.cards.filter(c => allowed.includes(c?.status));
  }

  setFilter(key: GiftCardFilter) {
    this.filter = key;
  }

  /** Empty-state subtitle key for the current filter (per-filter message). */
  get emptyFilterKey(): string {
    switch (this.filter) {
      case 'active':          return 'mgc_filter_empty_active';
      case 'pending_payment': return 'mgc_filter_empty_pending';
      case 'used':            return 'mgc_filter_empty_used';
      case 'expired':         return 'mgc_filter_empty_expired';
      case 'voided':          return 'mgc_filter_empty_voided';
      default:                return 'mgc_empty_subtitle';
    }
  }

  // Gift card id currently resuming payment (disables its button + shows label).
  payingCardId: number | null = null;

  private authToken = '';

  readonly cfImage = cfImage;

  // Status display map
  readonly statusMeta: Record<string, { label: string; color: string }>;

  constructor(
    private router: Router,
    private navCtrl: NavController,
    private network: MobileNetworkAdapter,
    private notify: AxNotificationService,
    private i18n: I18nService,
    private giftCardPayment: GiftCardPaymentService,
  ) {
    this.statusMeta = {
      pending_payment: { label: this.i18n.t('mgc_status_pending_payment'), color: '#F5A623' },
      active:          { label: this.i18n.t('gift_card_status_active'),    color: '#2D7D4F' },
      partially_used:  { label: this.i18n.t('gift_card_status_partial'),   color: '#2D7D4F' },
      exhausted:       { label: this.i18n.t('gift_card_status_exhausted'), color: '#8B6F47' },
      expired:         { label: this.i18n.t('gift_card_status_expired'),   color: '#C0392B' },
      voided:          { label: this.i18n.t('mgc_status_voided'),          color: '#C0392B' },
    };
  }

  ngOnInit() {
    // Loaded in ionViewWillEnter so the wallet refreshes on every entry (Ionic
    // caches the page, so ngOnInit runs only once, otherwise a card just
    // purchased/redeemed elsewhere doesn't appear until a manual refresh).
  }

  /** Set when navigating forward to the card detail so back skips the reload. */
  private skipReloadOnEnter = false;

  async ionViewWillEnter() {
    if (this.skipReloadOnEnter) { this.skipReloadOnEnter = false; return; }
    await this.loadAuthToken();
    this.load();
  }

  private async loadAuthToken() {
    const ret: any = await Preferences.get({ key: 'user' });
    if (ret?.value) {
      try { this.authToken = JSON.parse(ret.value)?.token ?? ''; } catch { /* ignore */ }
    }
  }

  load(event?: any) {
    this.ui.loading = true;
    this.network.get_v3('GET /gift-cards/mine', { authToken: this.authToken }).subscribe({
      next: (res: any) => {
        this.ui.loading = false;
        // Show ALL of the user's cards, including pending_payment (unpaid) ones
        // so the buyer can resume an interrupted checkout. Unpaid cards render
        // an inactive state with a "Complete payment" action (see template).
        this.cards = res?.data ?? [];
        event?.target?.complete();
      },
      error: (err: any) => {
        this.ui.loading = false;
        this.notify.error(apiErrorMessage(err, this.i18n.t('mgc_load_error')));
        event?.target?.complete();
      },
    });
  }

  openCard(card: any) {
    // Unpaid cards have no spendable code yet, tapping resumes payment
    // instead of opening the (empty) detail view.
    if (this.isUnpaid(card)) { this.completePayment(card); return; }
    this.skipReloadOnEnter = true;
    this.router.navigate(['/gift-card-detail'], { state: { card } });
  }

  isUnpaid(card: any): boolean {
    return card?.status === 'pending_payment';
  }

  /**
   * Copy a card's code straight from the wallet list.
   *
   * stopPropagation matters: the code sits inside the card tile, whose own
   * click opens the detail view, without it, copying would also navigate.
   */
  async copyCode(card: any, event: Event): Promise<void> {
    event.stopPropagation();
    event.preventDefault();
    const code = card?.code ?? '';
    if (!code) { return; }
    try {
      await navigator.clipboard.writeText(code);
      this.notify.success(this.i18n.t('gcd_code_copied'));
    } catch {
      // Clipboard unavailable (older webview), surface the code so the
      // customer can still read/select it.
      this.notify.info(code);
    }
  }

  /**
   * Resume checkout for an unpaid card. /checkout/initiate is idempotent
   * (returns the cached checkout URL), so this picks up where the buyer left
   * off. On success we reload the wallet so the now-active card refreshes.
   */
  completePayment(card: any) {
    if (this.payingCardId !== null) return; // a resume is already in flight
    if (!card?.id) return;
    this.payingCardId = card.id;
    const toSuccess = () => {
      this.payingCardId = null;
      // Same confirmation as a fresh purchase, the buyer sees the now-active
      // card rather than being dropped back on the list.
      this.router.navigate(['/gift-card-success'], {
        replaceUrl: true,
        queryParams: { cardId: card.id },
      });
    };
    this.giftCardPayment.pay(card.id, this.authToken, {
      onPaid: toSuccess,
      onFailed: () => {
        this.payingCardId = null;
        this.load();
      },
      onGatewaySkipped: toSuccess,
    });
  }

  buyNew() {
    this.router.navigate(['/gift-cards']);
  }

  redeemCode() {
    this.router.navigate(['/gift-card-redeem']);
  }

  goBack() { this.navCtrl.back(); }

  isSpendable(card: any): boolean {
    return card.is_spendable === true;
  }
}
