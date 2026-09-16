import { Component, OnInit } from '@angular/core';
import { FormsModule } from '@angular/forms';
import {
  IonContent,
  IonHeader,
  IonTitle,
  IonToolbar,
  IonButton,
  IonButtons,
  IonFooter,
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
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { Labels } from '../../class/labels';
import { cfImage } from '../../shared/cf-image';
import { AIN_WHATSAPP, ainWhatsappLink } from '../../core/constants/support.constants';

/** Money block from ProductSerializer (AED amounts). */
interface AinPrice {
  amount: number;
  currency: string;
}

/** Vendor embed on a concierge card. */
interface AinVendor {
  slug: string;
  name: string;
  legacy_id: number | null;
  id: number;
}

/**
 * One product Ain recommends: the v3 ProductSerializer::listShape card plus the
 * concierge's one-line `reason`. This endpoint is NOT run through the mobile
 * legacy response transform, so the fields are the raw v3 shape.
 */
interface AinCard {
  id: number;
  name: string;
  price: AinPrice | null;
  sale_price: AinPrice | null;
  primary_image: { url: string } | null;
  vendor: AinVendor | null;
  in_stock: boolean;
  reason?: string;
}

/** The stored `user` blob (only the bits we read). */
interface StoredUser {
  id: number;
  token: string;
}

/**
 * "Ask Ain" — 3bayti's AI style & gifting concierge. A conversational input
 * resolves a natural-language style/gift request to REAL, in-stock, ranked
 * product cards (each with a one-line reason), by POSTing to the concierge
 * endpoint. Works logged-out (personalises + enables "add to closet" when
 * signed in). Copy is LTR for both en/ar per the app-wide pin.
 */
@Component({
  selector: 'app-ask-ain',
  templateUrl: './ask-ain.page.html',
  styleUrls: ['./ask-ain.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonHeader,
    IonTitle,
    IonToolbar,
    IonButton,
    IonButtons,
    IonFooter,
    IonSpinner,
    IonGrid,
    IonRow,
    IonCol,
    FormsModule,
    TranslatePipe,
    AxIconComponent,
    AxWishlistSheetComponent,
  ],
})
export class AskAinPage implements OnInit {
  /** Expose cfImage for the template. */
  readonly cfImage = cfImage;

  /** Prompt chips shown on the empty state (i18n keys). */
  readonly suggestions = ['ask_ain_chip_wedding', 'ask_ain_chip_eid_gift', 'ask_ain_chip_casual_beige'];

  messageText = '';
  lastQuery = '';
  cards: AinCard[] = [];
  isSending = false;
  hasSearched = false;
  isEmpty = false;

  /** Whether the "Chat with Ain on WhatsApp" entry point is configured. */
  readonly ainWhatsappEnabled = AIN_WHATSAPP !== '';

  /** Open WhatsApp to chat with Ain, with a localized pre-filled greeting. */
  openAinWhatsapp(): void {
    const url = ainWhatsappLink(this.i18n.t('ask_ain_whatsapp_greeting'));
    if (url) {
      window.open(url, '_system');
    }
  }

  /** Interaction id of the latest concierge reply (threads the analytics events). */
  private interactionId: number | null = null;
  /** Persisted anonymous session id for concierge analytics. */
  private sessionId = '';

  imageLoaded: { [key: number]: boolean } = {};

  // Wishlist ("add to closet") state — only offered when signed in.
  private user: StoredUser | null = null;
  categories: Labels[] = [];
  isWishOpen = false;
  isLoadingCategory = false;
  isCreatingLabel = false;
  addCloset = { product_id: 0, product_name: '' };

  constructor(
    private nav: NavController,
    private router: Router,
    private networkAdapter: MobileNetworkAdapter,
    private wishlistService: WishlistService,
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
    this.recordEvent('ai_opened');
  }

  get isSignedIn(): boolean {
    return !!this.user?.token;
  }

  goBack(): void {
    this.nav.back();
  }

  useSuggestion(key: string): void {
    this.ask(this.i18n.t(key));
  }

  submit(): void {
    this.ask(this.messageText);
  }

  onKeyDown(event: KeyboardEvent): void {
    // Enter sends; Shift+Enter keeps a newline.
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.submit();
    }
  }

  ask(text: string): void {
    const query = (text ?? '').trim();
    if (!query || this.isSending) {
      return;
    }
    this.messageText = '';
    this.lastQuery = query;
    this.isSending = true;
    this.hasSearched = true;
    this.isEmpty = false;
    this.cards = [];
    this.imageLoaded = {};

    const body: Record<string, unknown> = {
      query,
      locale: this.i18n.lang,
      session_id: this.sessionId,
      channel: 'MOBILE',
    };
    const opts = this.user?.token ? { authToken: this.user.token } : {};

    this.networkAdapter.post_v3('POST /ai/concierge/style', body, opts).subscribe({
      next: (res: any) => {
        this.isSending = false;
        if (res?.response_code === 200 && res?.status === 'success' && res?.data) {
          this.interactionId = res.data.interaction_id ?? null;
          this.cards = Array.isArray(res.data.products) ? (res.data.products as AinCard[]) : [];
          this.isEmpty = this.cards.length === 0;
        } else {
          this.isEmpty = true;
          this.error_notification(this.i18n.t('ask_ain_error'));
        }
      },
      error: () => {
        this.isSending = false;
        this.isEmpty = true;
        this.error_notification(this.i18n.t('ask_ain_error'));
      },
    });
  }

  onImageLoad(id: number): void {
    this.imageLoaded[id] = true;
  }
  onImageError(id: number): void {
    this.imageLoaded[id] = true;
  }

  open_product(card: AinCard): void {
    this.recordEvent('ai_product_clicked', { product_id: card.id });
    // PDP resolves by v3 id (GET /v3/products/by-id/:id).
    this.router.navigate(['/', 'product'], { queryParams: { id: card.id, name: card.name } });
  }

  open_vendor(card: AinCard): void {
    if (!card.vendor?.slug) {
      return;
    }
    this.recordEvent('ai_vendor_clicked', { vendor_id: card.vendor.id });
    this.router.navigate(['/', 'vendors'], { queryParams: { slug: card.vendor.slug, name: card.vendor.name } });
  }

  // ── Add to closet (wishlist) — reuses ax-wishlist-sheet ─────────────────

  startAddToCloset(card: AinCard): void {
    if (!this.isSignedIn) {
      this.router.navigate(['/', 'login']);
      return;
    }
    this.addCloset.product_id = card.id;
    this.addCloset.product_name = card.name;
    this.get_label();
    this.isWishOpen = true;
  }

  private get_label(): void {
    if (!this.user?.token) {
      return;
    }
    this.isLoadingCategory = true;
    this.wishlistService
      .listLabels(this.user.token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.isLoadingCategory = false;
      })
      .catch(() => {
        this.isLoadingCategory = false;
      });
  }

  addToCloset(label: number): void {
    if (!this.user?.token) {
      return;
    }
    this.isWishOpen = false;
    this.isLoadingCategory = true;
    this.wishlistService
      .add(this.user.token, this.addCloset.product_id, label)
      .then((ok) => {
        if (ok) {
          this.success_notification(this.i18n.t('text_added_to_wishlist'));
        }
        this.isLoadingCategory = false;
      })
      .catch(() => {
        this.isLoadingCategory = false;
      });
  }

  async onCreateLabel(name: string): Promise<void> {
    if (this.isCreatingLabel || !this.user?.token) {
      return;
    }
    this.isCreatingLabel = true;
    try {
      const label = await this.wishlistService.createLabel(this.user.token, name);
      if (label) {
        this.addToCloset(label.id);
      } else {
        this.error_notification(this.i18n.t('network_error_retry'));
      }
    } catch {
      this.error_notification(this.i18n.t('network_error_retry'));
    } finally {
      this.isCreatingLabel = false;
    }
  }

  onDismiss(): void {
    this.isWishOpen = false;
  }

  // ── Analytics + session ────────────────────────────────────────────────

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
