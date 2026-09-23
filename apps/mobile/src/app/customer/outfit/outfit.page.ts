import { Component, ElementRef, OnInit, ViewChild } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonSpinner,
  NavController,
} from '@ionic/angular/standalone';
import { Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { firstValueFrom } from 'rxjs';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { I18nService } from '../../i18n.service';
import { cfImage } from '../../shared/cf-image';

interface Money {
  amount: number;
  currency: string;
}

interface OutfitProduct {
  id: number;
  name: string;
  price: Money | null;
  sale_price: Money | null;
  primary_image: { url: string } | null;
  vendor: { slug: string; name: string; id: number } | null;
  in_stock: boolean;
}

interface OutfitPiece {
  role: string;
  category_slug: string;
  reason: string;
  product: OutfitProduct;
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
 * "Style me with Ain": a guided mobile outfit generator. Occasion / hero garment
 * / vibe / colours / budget → a coordinated multi-piece look via POST /ai/outfit,
 * with "add the look to bag" and "save the look" (a signed-in Style). A gift-card
 * nudge is offered when no coherent look fits. Works logged-out; personalises
 * when signed in. v3-id / slug navigation only.
 */
@Component({
  selector: 'app-outfit',
  templateUrl: './outfit.page.html',
  styleUrls: ['./outfit.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonSpinner,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
  ],
})
export class OutfitPage implements OnInit {
  readonly cfImage = cfImage;

  readonly occasions = ['eid', 'wedding', 'party', 'graduation', 'ramadan', 'everyday'];
  readonly heroTypes = ['abaya', 'kaftan', 'dress'];
  readonly styleOptions = ['elegant', 'casual', 'traditional', 'modern', 'minimal', 'embellished'];
  readonly colourOptions = ['black', 'beige', 'rose', 'gold', 'navy', 'white', 'green', 'grey'];
  readonly budgetBands: BudgetBand[] = [
    { key: 'under300', max: 300 },
    { key: 'b300to700', min: 300, max: 700 },
    { key: 'b700to1500', min: 700, max: 1500 },
    { key: 'over1500', min: 1500 },
  ];

  private readonly colourSwatch: Record<string, string> = {
    black: '#2e241c', beige: '#e7d6bc', rose: '#c98a8a', gold: '#b18f1f',
    navy: '#2b3a55', white: '#f4efe7', green: '#4a6350', grey: '#9a938a',
  };

  occasion = '';
  heroType = '';
  budget: BudgetBand | null = null;
  selectedStyles: string[] = [];
  selectedColours: string[] = [];

  pieces: OutfitPiece[] = [];
  rationale = '';
  totalPrice: Money | null = null;
  giftCard: GiftCardSuggestion | null = null;
  isSending = false;
  hasSearched = false;
  isAdding = false;
  isSaving = false;
  saved = false;
  imageLoaded: { [key: number]: boolean } = {};

  @ViewChild('result') private resultEl?: ElementRef<HTMLElement>;

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
    this.recordEvent('ai_opened', { feature: 'outfit' });
  }

  goBack(): void {
    this.nav.back();
  }

  get isSignedIn(): boolean {
    return !!this.user?.token;
  }

  colourHex(name: string): string {
    return this.colourSwatch[name] ?? '#cbb79a';
  }

  pickOccasion(o: string): void {
    this.occasion = this.occasion === o ? '' : o;
  }
  pickHero(h: string): void {
    this.heroType = this.heroType === h ? '' : h;
  }
  pickBudget(b: BudgetBand): void {
    this.budget = this.budget?.key === b.key ? null : b;
  }
  toggleStyle(s: string): void {
    this.selectedStyles = this.selectedStyles.includes(s)
      ? this.selectedStyles.filter((x) => x !== s)
      : [...this.selectedStyles, s];
  }
  toggleColour(c: string): void {
    this.selectedColours = this.selectedColours.includes(c)
      ? this.selectedColours.filter((x) => x !== c)
      : [...this.selectedColours, c];
  }
  isStyleOn(s: string): boolean {
    return this.selectedStyles.includes(s);
  }
  isColourOn(c: string): boolean {
    return this.selectedColours.includes(c);
  }

  get canSubmit(): boolean {
    return !!this.occasion || this.selectedStyles.length > 0 || this.selectedColours.length > 0 || !!this.budget || !!this.heroType;
  }

  get isEmpty(): boolean {
    return this.hasSearched && !this.isSending && this.pieces.length === 0 && !this.giftCard;
  }

  generate(): void {
    if (!this.canSubmit || this.isSending) {
      return;
    }
    this.recordEvent('ai_outfit_started');

    const body: Record<string, unknown> = {
      occasion: this.occasion || undefined,
      styles: this.selectedStyles,
      colours: this.selectedColours,
      product_type: this.heroType || undefined,
      budget_min: this.budget?.min,
      budget_max: this.budget?.max,
      locale: this.i18n.lang,
      session_id: this.sessionId,
      channel: 'MOBILE',
    };
    const opts = this.user?.token ? { authToken: this.user.token } : {};

    this.isSending = true;
    this.hasSearched = true;
    this.saved = false;
    this.pieces = [];
    this.rationale = '';
    this.giftCard = null;
    this.totalPrice = null;
    this.imageLoaded = {};

    this.networkAdapter.post_v3('POST /ai/outfit', body, opts).subscribe({
      next: (res: any) => {
        this.isSending = false;
        if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
          this.interactionId = res.data.interaction_id ?? null;
          this.pieces = Array.isArray(res.data.items) ? (res.data.items as OutfitPiece[]) : [];
          this.rationale = typeof res.data.rationale === 'string' ? res.data.rationale : '';
          this.totalPrice = res.data.total_price ?? null;
          this.giftCard = res.data.gift_card_suggestion ?? null;
          this.recordEvent('ai_outfit_generated', { count: this.pieces.length });
          if (this.pieces.length > 0) {
            this.scrollToResult();
          }
        } else {
          this.error_notification(this.i18n.t('outfit_error'));
        }
      },
      error: () => {
        this.isSending = false;
        this.error_notification(this.i18n.t('outfit_error'));
      },
    });
  }

  /** Bring the generated look into view — it renders below the tall form. */
  private scrollToResult(): void {
    setTimeout(() => {
      this.resultEl?.nativeElement.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }, 120);
  }

  onImageLoad(id: number): void {
    this.imageLoaded[id] = true;
  }
  onImageError(id: number): void {
    this.imageLoaded[id] = true;
  }

  open_product(piece: OutfitPiece): void {
    this.recordEvent('ai_product_clicked', { product_id: piece.product.id });
    this.router.navigate(['/', 'product'], { queryParams: { id: piece.product.id, name: piece.product.name } });
  }

  /** Add every piece of the look to the bag. */
  async addLook(): Promise<void> {
    if (this.pieces.length === 0 || this.isAdding) {
      return;
    }
    if (!this.user?.token) {
      this.error_notification(this.i18n.t('sign_in_to_add_to_cart'));
      return;
    }
    this.isAdding = true;
    let added = 0;
    for (const piece of this.pieces) {
      const body = {
        product_id: piece.product.id,
        quantity: 1,
        size: '',
        color: '',
        is_custom: false,
        measurement: null,
        extra_measurement: null,
        note: null,
      };
      try {
        const res: any = await firstValueFrom(
          this.networkAdapter.post_v3('POST /cart/items', body, { authToken: this.user.token }),
        );
        const ok = res?.response_code === 200 && (res?.status === 'success' || (res?.data && res.data.success === true));
        if (ok) {
          added++;
          this.recordEvent('ai_outfit_item_added', { product_id: piece.product.id });
        }
      } catch {
        // Skip a piece that can't be added; keep going.
      }
    }
    this.isAdding = false;
    this.recordEvent('ai_outfit_added', { count: added });
    if (added > 0) {
      this.success_notification(this.i18n.t('outfit_added_toast', { count: added }));
    } else {
      this.error_notification(this.i18n.t('outfit_add_failed_toast'));
    }
  }

  /** Save the composed look as an Ain Style (signed-in only, <= 4 pieces). */
  saveLook(): void {
    if (!this.user?.token) {
      this.router.navigate(['/', 'login']);
      return;
    }
    if (this.pieces.length === 0 || this.isSaving) {
      return;
    }
    this.isSaving = true;
    const body = {
      name: this.lookName(),
      products: this.pieces.slice(0, 4).map((p) => p.product.id),
      source: 'ai',
      prompt: this.promptSummary(),
      rationale: this.rationale,
    };
    this.networkAdapter.post_v3('POST /me/styles', body, { authToken: this.user.token }).subscribe({
      next: (res: any) => {
        this.isSaving = false;
        if ((res?.response_code === 201 || res?.response_code === 200) && res?.status === 'success') {
          this.saved = true;
          this.recordEvent('ai_outfit_saved', { count: this.pieces.length });
          this.success_notification(this.i18n.t('outfit_saved_toast'));
        } else {
          this.error_notification(this.i18n.t('outfit_save_failed_toast'));
        }
      },
      error: () => {
        this.isSaving = false;
        this.error_notification(this.i18n.t('outfit_save_failed_toast'));
      },
    });
  }

  openGiftCards(): void {
    this.recordEvent('ai_gift_card_recommended', { context: 'outfit', surface: 'mobile' });
    this.router.navigate(['/', 'gift-cards']);
  }

  private lookName(): string {
    const parts = [this.selectedStyles[0], this.occasion].filter((s) => !!s);
    const label = parts.join(' ').trim();
    return label !== '' ? label.charAt(0).toUpperCase() + label.slice(1) + ' look' : 'My Ain outfit';
  }

  private promptSummary(): string {
    const b: string[] = [];
    if (this.occasion) b.push(this.occasion);
    if (this.selectedStyles.length) b.push(this.selectedStyles.join(', '));
    if (this.selectedColours.length) b.push('in ' + this.selectedColours.join(', '));
    if (this.heroType) b.push('around a ' + this.heroType);
    return b.join(' · ');
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
  private success_notification(message: string): void {
    this.toast.success(message, { position: 'top-center' });
  }
}
