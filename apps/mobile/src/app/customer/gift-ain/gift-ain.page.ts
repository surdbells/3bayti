import { Component, OnInit, ViewChild } from '@angular/core';
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
import { ActivatedRoute, Router } from '@angular/router';
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

  /* Fixed-option pickers for the optional detail hints — no free text, so the
   * brief maps onto canonical tags. Same vocabulary as the Outfit generator. */
  readonly colourOptions = ['black', 'beige', 'rose', 'gold', 'navy', 'white', 'green', 'grey'];
  readonly styleOptions = ['elegant', 'casual', 'traditional', 'modern', 'minimal', 'embellished'];
  readonly sizeOptions = ['XS', 'S', 'M', 'L', 'XL', 'XXL'];

  private readonly colourSwatch: Record<string, string> = {
    black: '#2e241c', beige: '#e7d6bc', rose: '#c98a8a', gold: '#b18f1f',
    navy: '#2b3a55', white: '#f4efe7', green: '#4a6350', grey: '#9a938a',
  };

  /** Stroke-only SVG path data (24×24, currentColor) for each recipient avatar. */
  private readonly recipientIcons: Record<string, string[]> = {
    sister: ['M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2', 'M13 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0', 'M22 21v-2a4 4 0 0 0-3-3.87', 'M16 3.13a4 4 0 0 1 0 7.75'],
    mother: ['M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'],
    friend: ['M12 22a10 10 0 1 1 0-20 10 10 0 0 1 0 20z', 'M8 14s1.5 2 4 2 4-2 4-2', 'M9 9h.01', 'M15 9h.01'],
    wife: ['M12 3 8 9l4 12 4-12z', 'M8 9h8', 'M9.5 3h5'],
    daughter: ['M19 21v-2a4 4 0 0 0-4-4H9a4 4 0 0 0-4 4v2', 'M16 7a4 4 0 1 1-8 0 4 4 0 0 1 8 0'],
    colleague: ['M20 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2z', 'M16 7V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v2', 'M2 12h20'],
  };

  /** Stroke-only SVG path data for each occasion icon. */
  private readonly occasionIcons: Record<string, string[]> = {
    eid: ['M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z'],
    wedding: ['M14 9a5 5 0 1 1-10 0 5 5 0 0 1 10 0', 'M20 15a5 5 0 1 1-10 0 5 5 0 0 1 10 0'],
    birthday: ['M20 21v-8a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8', 'M4 16s.5-1 2-1 2.5 2 4 2 2.5-2 4-2 2.5 2 4 2 2-1 2-1', 'M2 21h20', 'M7 8v2', 'M12 8v2', 'M17 8v2', 'M7 4h.01', 'M12 4h.01', 'M17 4h.01'],
    graduation: ['M22 10 12 5 2 10l10 5 10-5z', 'M6 12v5c0 1 2.5 3 6 3s6-2 6-3v-5', 'M22 10v6'],
    anniversary: ['M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z'],
    just_because: ['M12 3l1.9 4.8L18.7 9.7 13.9 11.6 12 16.4 10.1 11.6 5.3 9.7 10.1 7.8z', 'M19 15l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z'],
  };

  recipientIcon(key: string): string[] {
    return this.recipientIcons[key] ?? [];
  }
  occasionIcon(key: string): string[] {
    return this.occasionIcons[key] ?? [];
  }

  budgetTier(b: BudgetBand): number {
    return this.budgetBands.indexOf(b) + 1;
  }

  recipient = '';
  occasion = '';
  budget: BudgetBand | null = null;
  selectedColours: string[] = [];
  selectedStyles: string[] = [];
  size = '';

  cards: GiftItem[] = [];
  giftCard: GiftCardSuggestion | null = null;
  isSending = false;
  hasSearched = false;
  imageLoaded: { [key: number]: boolean } = {};

  @ViewChild(IonContent) private content?: IonContent;

  private user: StoredUser | null = null;
  private sessionId = '';
  private interactionId: number | null = null;

  constructor(
    private nav: NavController,
    private router: Router,
    private route: ActivatedRoute,
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
    this.applyPrefill();
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

  colourHex(name: string): string {
    return this.colourSwatch[name] ?? '#cbb79a';
  }
  toggleColour(c: string): void {
    this.selectedColours = this.selectedColours.includes(c)
      ? this.selectedColours.filter((x) => x !== c)
      : [...this.selectedColours, c];
  }
  toggleStyle(s: string): void {
    this.selectedStyles = this.selectedStyles.includes(s)
      ? this.selectedStyles.filter((x) => x !== s)
      : [...this.selectedStyles, s];
  }
  isColourOn(c: string): boolean {
    return this.selectedColours.includes(c);
  }
  isStyleOn(s: string): boolean {
    return this.selectedStyles.includes(s);
  }
  pickSize(s: string): void {
    this.size = this.size === s ? '' : s;
  }

  get canSubmit(): boolean {
    return !!this.occasion || !!this.budget || this.selectedColours.length > 0 || this.selectedStyles.length > 0;
  }

  get isEmpty(): boolean {
    return this.hasSearched && !this.isSending && this.cards.length === 0 && !this.giftCard;
  }

  submit(): void {
    if (!this.canSubmit || this.isSending) {
      return;
    }
    this.runBrief({
      recipient: this.recipient || undefined,
      occasion: this.occasion === 'just_because' ? undefined : this.occasion || undefined,
      colours: this.selectedColours,
      styles: this.selectedStyles,
      size: this.size || undefined,
      budget_min: this.budget?.min,
      budget_max: this.budget?.max,
    });
  }

  /** Return to the brief form (selections kept) for a new/edited search. */
  editSearch(): void {
    this.hasSearched = false;
    this.isSending = false;
    this.cards = [];
    this.giftCard = null;
  }

  /** The form is hidden on response, so snap the content to the top to reveal
   * the thinking state → results. IonContent always exists (unlike a @if ref),
   * so this fires reliably where the element-ref scroll did not. */
  private scrollToResults(): void {
    setTimeout(() => this.content?.scrollToTop(300), 80);
  }

  private runBrief(brief: Record<string, unknown>): void {
    this.recordEvent('ai_gift_started');

    const body: Record<string, unknown> = {
      ...brief,
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
    this.scrollToResults();

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

  goReminders(): void {
    this.router.navigate(['/', 'gift-reminders']);
  }

  private applyPrefill(): void {
    const params = this.route.snapshot.queryParamMap;
    const occasion = (params.get('occasion') ?? '').trim();
    const budgetMaxRaw = params.get('budget_max');
    const categorySlug = params.get('category_slug') ?? undefined;
    const reminderId = params.get('gift_reminder_id');
    const budgetMax = budgetMaxRaw !== null && budgetMaxRaw !== '' ? Number(budgetMaxRaw) : undefined;

    if (!occasion && budgetMax === undefined) {
      return;
    }
    if (reminderId) {
      this.recordEvent('gift_reminder_clicked', { gift_reminder_id: Number(reminderId) });
    }

    const occKey = this.occasions.find((o) => o.toLowerCase() === occasion.toLowerCase());
    if (occKey) {
      this.occasion = occKey;
    }
    if (budgetMax !== undefined) {
      const band = this.budgetBands.find((b) => (b.max ?? Infinity) >= budgetMax && (b.min ?? 0) <= budgetMax);
      if (band) {
        this.budget = band;
      }
    }

    this.runBrief({ occasion: occasion || undefined, budget_max: budgetMax, category_slug: categorySlug });
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
