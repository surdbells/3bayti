import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router } from '@angular/router';
import {
  IonButton, IonButtons, IonContent, IonHeader, IonTitle, IonToolbar,
  IonRefresher, IonRefresherContent, NavController,
} from '@ionic/angular/standalone';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { Preferences } from '@capacitor/preferences';
import { GiftCardPaymentService } from '../gift-cards/gift-card-payment.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { GlobalComponent } from '../../global-component';
import { cfImage } from '../../shared/cf-image';
import { TranslatePipe } from '../../translate.pipe';
import { I18nService } from '../../i18n.service';

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

  async ngOnInit() {
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
      error: () => {
        this.ui.loading = false;
        this.notify.error(this.i18n.t('mgc_load_error'));
        event?.target?.complete();
      },
    });
  }

  openCard(card: any) {
    // Unpaid cards have no spendable code yet — tapping resumes payment
    // instead of opening the (empty) detail view.
    if (this.isUnpaid(card)) { this.completePayment(card); return; }
    this.router.navigate(['/gift-card-detail'], { state: { card } });
  }

  isUnpaid(card: any): boolean {
    return card?.status === 'pending_payment';
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
    this.giftCardPayment.pay(card.id, this.authToken, {
      onPaid: () => {
        this.payingCardId = null;
        this.load();
      },
      onFailed: () => {
        this.payingCardId = null;
        this.load();
      },
      onGatewaySkipped: () => {
        this.payingCardId = null;
        this.load();
      },
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
