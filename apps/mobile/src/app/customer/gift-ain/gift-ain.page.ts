import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonSpinner,
  IonGrid,
  IonRow,
  IonCol,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';

interface GiftPrice {
  amount: number;
  currency: string;
}

interface GiftVendor {
  slug: string;
  name: string;
  id: number;
}

/** A gift idea card: v3 listShape product + Ain's reason. */
interface GiftItem {
  id: number;
  name: string;
  price: GiftPrice | null;
  sale_price: GiftPrice | null;
  primary_image: { url: string } | null;
  vendor: GiftVendor | null;
  in_stock: boolean;
  reason?: string;
}

interface GiftCardSuggestion {
  reason: string;
  suggested_denomination: string | null;
  presets: string[];
  currency: string;
}

interface BudgetBand {
  key: string;
  min?: number;
  max?: number;
}

interface StoredUser {
  id: number;
  token: string;
}

/**
 * "Ask Ain — find a gift": a guided mobile gifting flow. Recipient / occasion /
 * budget (+ optional colour, style, size) → real, in-stock gift ideas via
 * POST /ai/concierge/gift, plus a gift-card nudge when the gift is risky. Works
 * logged-out; personalises when signed in. v3-id / slug navigation only.
 */
@Component({
  selector: 'app-gift-ain',
  templateUrl: './gift-ain.page.html',
  styleUrls: ['./gift-ain.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonSpinner,
    IonGrid,
    IonRow,
    IonCol,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class GiftAinPage implements OnInit {
  readonly cfImage = cfImage;

  readonly recipients = ['sister', 'mother', 'friend', 'wife', 'daughter', 'colleague'];
  readonly occasions = ['eid', 'wedding', 'birthday', 'graduation', 'anniversary', 'just_because'];
  readonly budgetBands: BudgetBand[] = [
    { key: 'under200', max: 200 },
    { key: 'b200to500', min: 200, max: 500 },
    { key: 'b500to1000', min: 500, max: 1000 },
    { key: 'over1000', min: 1000 },
  ];

  recipient = '';
  occasion = '';
  budget: BudgetBand | null = null;
  coloursText = '';
  stylesText = '';
  size = '';

  cards: GiftItem[] = [];
  giftCard: GiftCardSuggestion | null = null;
  isSending = false;
  hasSearched = false;
  imageLoaded: { [key: number]: boolean } = {};

  private user: StoredUser | null = null;
  private sessionId = '';
  private interactionId: number | null = null;

  constructor(
    private nav: NavController,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private i18n: I18nService,
    private toast: AxNotificationService,
  ) {}

  async ngOnInit(): Promise<void> {
    const ret = await Preferences.get({ key: 'user' });
    if (ret.value) {
      try {
        this.user = JSON.parse(ret.value) as StoredUser;
      } catch {
        this.user = null;
      }
    }
    this.sessionId = await this.ensureSession();
  }

  goBack(): void {
    this.nav.back();
  }

  pickRecipient(r: string): void {
    this.recipient = this.recipient === r ? '' : r;
  }
  pickOccasion(o: string): void {
    this.occasion = this.occasion === o ? '' : o;
  }
  pickBudget(b: BudgetBand): void {
    this.budget = this.budget?.key === b.key ? null : b;
  }

  get canSubmit(): boolean {
    return !!this.occasion || !!this.budget || !!this.coloursText.trim() || !!this.stylesText.trim();
  }

  get isEmpty(): boolean {
    return this.hasSearched && !this.isSending && this.cards.length === 0 && !this.giftCard;
  }

  private toList(text: string): string[] {
    return text.split(',').map((s) => s.trim()).filter((s) => s.length > 0);
  }

  submit(): void {
    if (!this.canSubmit || this.isSending) {
      return;
    }
    this.recordEvent('ai_gift_started');

    const body: Record<string, unknown> = {
      recipient: this.recipient || undefined,
      occasion: this.occasion === 'just_because' ? undefined : this.occasion || undefined,
      colours: this.toList(this.coloursText),
      styles: this.toList(this.stylesText),
      size: this.size.trim() || undefined,
      budget_min: this.budget?.min,
      budget_max: this.budget?.max,
      locale: this.i18n.lang,
      session_id: this.sessionId,
      channel: 'MOBILE',
    };
    const opts = this.user?.token ? { authToken: this.user.token } : {};

    this.isSending = true;
    this.hasSearched = true;
    this.cards = [];
    this.giftCard = null;
    this.imageLoaded = {};

    this.networkAdapter.post_v3('POST /ai/concierge/gift', body, opts).subscribe({
      next: (res: any) => {
        this.isSending = false;
        if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
          this.interactionId = res.data.interaction_id ?? null;
          this.cards = Array.isArray(res.data.products) ? (res.data.products as GiftItem[]) : [];
          this.giftCard = res.data.gift_card_suggestion ?? null;
        } else {
          this.error_notification(this.i18n.t('gift_ain_error'));
        }
      },
      error: () => {
        this.isSending = false;
        this.error_notification(this.i18n.t('gift_ain_error'));
      },
    });
  }

  onImageLoad(id: number): void {
    this.imageLoaded[id] = true;
  }
  onImageError(id: number): void {
    this.imageLoaded[id] = true;
  }

  open_product(card: GiftItem): void {
    this.recordEvent('ai_product_clicked', { product_id: card.id });
    this.router.navigate(['/', 'product'], { queryParams: { id: card.id, name: card.name } });
  }

  open_vendor(card: GiftItem): void {
    if (!card.vendor?.slug) {
      return;
    }
    this.recordEvent('ai_vendor_clicked', { vendor_id: card.vendor.id });
    this.router.navigate(['/', 'vendors'], { queryParams: { slug: card.vendor.slug, name: card.vendor.name } });
  }

  openGiftCards(): void {
    this.router.navigate(['/', 'gift-cards']);
  }

  private recordEvent(event: string, extra: Record<string, unknown> = {}): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId, ...extra };
      if (this.interactionId) {
        body['interaction_id'] = this.interactionId;
      }
      const opts = this.user?.token ? { authToken: this.user.token } : {};
      this.networkAdapter.post_v3('POST /ai/events', body, opts).subscribe({ next: () => {}, error: () => {} });
    } catch {
      // Analytics must never break the page.
    }
  }

  private async ensureSession(): Promise<string> {
    const got = await Preferences.get({ key: 'ain_session' });
    if (got.value) {
      return got.value;
    }
    const id =
      typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function'
        ? crypto.randomUUID()
        : 'm-' + Date.now().toString(36) + '-' + Math.floor(Math.random() * 1e9).toString(36);
    await Preferences.set({ key: 'ain_session', value: id });
    return id;
  }

  private error_notification(message: string): void {
    this.toast.error(message, { position: 'top-center' });
  }
}
