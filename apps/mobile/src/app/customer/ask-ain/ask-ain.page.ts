import { Component, ElementRef, OnDestroy, OnInit, ViewChild, inject } from '@angular/core';
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
import { ActivatedRoute, Router } from '@angular/router';
import { Preferences } from '@capacitor/preferences';
import { Subscription } from 'rxjs';

import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { SessionStore } from '../../core/services/session-store.service';
import { AxNotificationService } from '../../shared/ax-mobile/notification';
import { TranslatePipe } from '../../translate.pipe';
import { AxIconComponent } from '../../shared/ax-mobile/icon';
import { AxWishlistSheetComponent } from '../../shared/ax-mobile/wishlist-sheet';
import { WishlistService } from '../../core/services/wishlist.service';
import { I18nService } from '../../i18n.service';
import { Labels } from '../../class/labels';
import { cfImage } from '../../shared/cf-image';
import { AIN_WHATSAPP, ainWhatsappLink } from '../../core/constants/support.constants';
import {
  AIN_MAX_QUESTIONS,
  AinAskContext,
  AinCard,
  AinErrorTurn,
  AinReplyTurn,
  AinTurn,
  AskAinThreadService,
} from './ask-ain-thread.service';

/**
 * "Ask Ain" — 3bayti's AI style & gifting concierge, as a chat thread. Each
 * customer message is POSTed to the concierge with the recent conversation
 * (text only) as `history`, so follow-ups like "cheaper" or "in black" refine
 * the previous request. Ain answers with a short reply, real in-stock product
 * cards (each with a one-line reason) and tappable follow-up suggestions.
 *
 * The thread lives in AskAinThreadService (a root service), so it survives
 * opening a product and coming back. Works logged-out (personalises + enables
 * "add to closet" when signed in). Copy is LTR for both en/ar per the app-wide
 * pin. A `?q=` query param starts the conversation with that message.
 *
 * Everything happens inside the conversation: the thread always opens with
 * Ain's own greeting bubble (local copy, no API call, never sent as history,
 * no preset chips), then Ain asks up to 6 guided questions before showing
 * products. There is no separate empty state outside the thread.
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
export class AskAinPage implements OnInit, OnDestroy {
  /** Expose cfImage for the template. */
  readonly cfImage = cfImage;

  /** Upper bound of guided questions (shown in the progress hint). */
  readonly maxQuestions = AIN_MAX_QUESTIONS;

  readonly thread = inject(AskAinThreadService);

  messageText = '';

  /** Whether the "chat with Ain on WhatsApp" link (in the opening bubble) is configured. */
  readonly ainWhatsappEnabled = AIN_WHATSAPP !== '';

  @ViewChild(IonContent) private content?: IonContent;
  @ViewChild('composer') private composer?: ElementRef<HTMLTextAreaElement>;

  /** Persisted anonymous session id for concierge analytics. */
  private sessionId = '';
  private sessionReady: Promise<void> = Promise.resolve();
  private querySub: Subscription | null = null;
  private destroyed = false;

  imageLoaded: { [key: number]: boolean } = {};

  // Wishlist ("add to closet") state — only offered when signed in.
  categories: Labels[] = [];
  isWishOpen = false;
  isLoadingCategory = false;
  isCreatingLabel = false;
  addCloset = { product_id: 0, product_name: '' };

  private nav = inject(NavController);
  private router = inject(Router);
  private route = inject(ActivatedRoute);
  private networkAdapter = inject(MobileNetworkAdapter);
  private wishlistService = inject(WishlistService);
  private i18n = inject(I18nService);
  private toast = inject(AxNotificationService);
  private session = inject(SessionStore);

  /** No messages yet: only Ain's opening bubble is in the thread. */
  get isFresh(): boolean {
    return this.thread.isEmpty;
  }

  get turns(): AinTurn[] {
    return this.thread.turns();
  }

  get isSending(): boolean {
    return this.thread.pending();
  }

  /**
   * The one line screen readers hear for the newest turn: "typing" while Ain
   * answers, then Ain's reply text (or the error copy). Empty on a fresh thread.
   */
  get announcement(): string {
    if (this.isSending) {
      return this.i18n.t('ask_ain_thinking');
    }
    const last = this.turns[this.turns.length - 1];
    if (!last || last.role === 'user') {
      return '';
    }
    return last.role === 'ain' ? last.text : this.i18n.t('ask_ain_error');
  }

  /** Id of the newest Ain reply: only its follow-up chips are offered. */
  get lastReplyId(): number | null {
    const turns = this.turns;
    const last = turns[turns.length - 1];
    return last && last.role === 'ain' ? last.id : null;
  }

  get isSignedIn(): boolean {
    return !!this.session.currentAccessToken();
  }

  ngOnInit(): void {
    this.sessionReady = this.initSession();
    // A different account signed in since this thread started: start fresh.
    this.thread.ensureOwner();
    this.querySub = this.route.queryParamMap.subscribe((params) => {
      const q = (params.get('q') ?? '').trim();
      // Keyed on the navigation too: a page re-created on the same history
      // entry does not resend, but a fresh navigation with the same q does.
      const key = `${this.navigationKey()}|${q}`;
      if (q && key !== this.thread.consumedDeepLink) {
        this.thread.consumedDeepLink = key;
        // A deep link starts a fresh conversation about that request.
        this.thread.reset();
        void this.ask(q);
      }
    });
    // Re-created page with an existing thread: show the newest message.
    if (!this.thread.isEmpty) {
      this.scrollToEnd(0);
    }
  }

  ngOnDestroy(): void {
    this.destroyed = true;
    this.querySub?.unsubscribe();
  }

  private async initSession(): Promise<void> {
    try {
      await this.session.ready();
    } catch {
      // Unreadable session: Ain still works signed-out.
    }
    try {
      this.sessionId = await this.ensureSession();
    } catch {
      this.sessionId = '';
    }
    this.recordEvent('ai_opened');
  }

  /** Open WhatsApp to chat with Ain, with a localized pre-filled greeting. */
  openAinWhatsapp(): void {
    const url = ainWhatsappLink(this.i18n.t('ask_ain_whatsapp_greeting'));
    if (url) {
      window.open(url, '_system');
    }
  }

  goBack(): void {
    this.nav.back();
  }

  /** "New chat": clear the thread back to just Ain's opening bubble. */
  newChat(): void {
    this.thread.reset();
    this.messageText = '';
    this.imageLoaded = {};
    this.focusComposer();
  }

  /**
   * Tap on a follow-up / answer chip: send it as the customer's next message.
   * The "Show me what you have" chip also tells Ain to skip the remaining
   * guided questions (`show_now`).
   */
  useFollowUp(text: string): void {
    void this.ask(text, this.isSkipChip(text));
  }

  /** Whether a chip is the "Show me what you have" skip chip (styled apart). */
  isSkipChip(text: string): boolean {
    return this.thread.isSkipChip(text);
  }

  /**
   * "Question 2 of up to 6" for a guided-question turn, or '' for any other
   * turn (results, small talk, or an older API without `question_slot`).
   */
  questionProgress(turn: AinReplyTurn): string {
    // Only the newest question shows the hint (matches web).
    if (!turn.questionSlot || turn.id !== this.lastReplyId) {
      return '';
    }
    const asked = turn.askedSlots?.length ?? 0;
    const current = Math.min(Math.max(asked, 1), AIN_MAX_QUESTIONS);
    return this.i18n.t('ask_ain_question_progress', { current, total: AIN_MAX_QUESTIONS });
  }

  submit(): void {
    void this.ask(this.messageText);
  }

  onKeyDown(event: KeyboardEvent): void {
    // Enter sends; Shift+Enter keeps a newline.
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      this.submit();
    }
  }

  async ask(text: string, showNow = false): Promise<void> {
    const query = (text ?? '').trim();
    // Keep focus (and the keyboard) in the composer whatever was tapped.
    this.focusComposer();
    if (!query || this.isSending) {
      return;
    }
    const typed = this.messageText;
    this.messageText = '';
    // The user bubble + typing indicator appear synchronously inside send();
    // the context (session id, token) resolves while Ain is "typing".
    const done = this.thread.send(query, this.context(), showNow ? { showNow: true } : {});
    this.scrollToEnd();
    if (!(await done)) {
      // Refused (another message in flight): give the typed text back.
      if (!this.messageText) {
        this.messageText = typed;
      }
      return;
    }
    this.scrollToEnd();
  }

  /** Retry a failed turn (the thread is kept). Only the newest turn offers it. */
  async retry(turn: AinErrorTurn): Promise<void> {
    this.focusComposer();
    const done = this.thread.retry(turn.id, this.context());
    this.scrollToEnd();
    await done;
    this.scrollToEnd();
  }

  /** Whether this error turn is the newest one (only it offers Retry). */
  canRetry(turn: AinTurn): boolean {
    return this.thread.isLast(turn.id);
  }

  /** Keep the composer focused when a button is pressed (keyboard stays open). */
  keepComposerFocus(event: Event): void {
    event.preventDefault();
  }

  /**
   * Identifies the current navigation (Angular stores `navigationId` in the
   * history entry's state; a back navigation restores the same id).
   */
  protected navigationKey(): string {
    try {
      const id = (window.history.state as { navigationId?: unknown } | null)?.navigationId;
      return id === undefined || id === null ? '' : String(id);
    } catch {
      return '';
    }
  }

  private async context(): Promise<AinAskContext> {
    await this.sessionReady;
    const token = this.session.currentAccessToken();
    return { locale: this.i18n.lang, sessionId: this.sessionId, ...(token ? { authToken: token } : {}) };
  }

  asReply(turn: AinTurn): AinReplyTurn {
    return turn as AinReplyTurn;
  }

  asError(turn: AinTurn): AinErrorTurn {
    return turn as AinErrorTurn;
  }

  onImageLoad(id: number): void {
    this.imageLoaded[id] = true;
  }
  onImageError(id: number): void {
    this.imageLoaded[id] = true;
  }

  open_product(card: AinCard, turn?: AinReplyTurn): void {
    this.recordEvent('ai_product_clicked', { product_id: card.id }, turn?.interactionId ?? null);
    // PDP resolves by v3 id (GET /v3/products/by-id/:id).
    this.router.navigate(['/', 'product'], { queryParams: { id: card.id, name: card.name } });
  }

  open_vendor(card: AinCard, turn?: AinReplyTurn): void {
    if (!card.vendor?.slug) {
      return;
    }
    this.recordEvent('ai_vendor_clicked', { vendor_id: card.vendor.id }, turn?.interactionId ?? null);
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
    const token = this.session.currentAccessToken();
    if (!token) {
      return;
    }
    this.isLoadingCategory = true;
    this.wishlistService
      .listLabels(token)
      .then((labels) => {
        this.categories = labels.map((l) => ({ id: l.id, name: l.name, count: l.count })) as any;
        this.isLoadingCategory = false;
      })
      .catch(() => {
        this.isLoadingCategory = false;
      });
  }

  addToCloset(label: number): void {
    const token = this.session.currentAccessToken();
    if (!token) {
      return;
    }
    this.isWishOpen = false;
    this.isLoadingCategory = true;
    this.wishlistService
      .add(token, this.addCloset.product_id, label)
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
    const token = this.session.currentAccessToken();
    if (this.isCreatingLabel || !token) {
      return;
    }
    this.isCreatingLabel = true;
    try {
      const label = await this.wishlistService.createLabel(token, name);
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

  // ── Scroll + focus ─────────────────────────────────────────────────────

  private scrollToEnd(duration = 250): void {
    // After the new turn renders.
    setTimeout(() => {
      if (this.destroyed) {
        return;
      }
      try {
        void this.content?.scrollToBottom(duration);
      } catch {
        // Scrolling is cosmetic.
      }
    }, 50);
  }

  private focusComposer(): void {
    setTimeout(() => {
      if (!this.destroyed) {
        this.composer?.nativeElement.focus();
      }
    }, 0);
  }

  // ── Analytics + session ────────────────────────────────────────────────

  private recordEvent(event: string, extra: Record<string, unknown> = {}, interactionId: number | null = null): void {
    try {
      const body: Record<string, unknown> = { event, session_id: this.sessionId, ...extra };
      if (interactionId) {
        body['interaction_id'] = interactionId;
      }
      const token = this.session.currentAccessToken();
      const opts = token ? { authToken: token } : {};
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
