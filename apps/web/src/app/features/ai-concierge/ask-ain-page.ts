import {
  ChangeDetectionStrategy,
  Component,
  ElementRef,
  Injector,
  OnInit,
  PLATFORM_ID,
  afterNextRender,
  computed,
  effect,
  inject,
  signal,
  viewChild,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router, RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';

import { ProductCardComponent } from '../catalog/product-card';
import { ConciergeService, type ConciergeCard } from './concierge.service';
import { AskAinThreadService, type AinTurn } from './ask-ain-thread.service';
import { AIN_WHATSAPP_NUMBER, ainWhatsappUrl } from '../../core/config/support.constants';

/**
 * "Ask Ain" — 3bayti's AI style & gifting concierge (web), as a chat thread.
 *
 * Each customer message is answered by Ain with a short reply, REAL in-stock
 * ranked products (each with Ain's one-line reason, rendered with the shared
 * ui-product-card) and tappable follow-up suggestions. The last 8 turns (text
 * only) go back to the API as history so refinements like "cheaper" or "in
 * black" keep the earlier constraints. The thread lives in a root service
 * (+ sessionStorage), so it survives opening a product and coming back.
 * Works for guests; personalises when signed in. Navigation is slug-based via
 * the card's own anchor — no legacy ids. `?q=` starts a new conversation.
 */
@Component({
  selector: 'app-ask-ain',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, TranslatePipe, ProductCardComponent, RouterLink],
  templateUrl: './ask-ain-page.html',
  styleUrl: './ask-ain-page.scss',
})
export class AskAinPageComponent implements OnInit {
  private readonly concierge = inject(ConciergeService);
  private readonly translate = inject(TranslateService);
  private readonly route = inject(ActivatedRoute);
  private readonly router = inject(Router);
  private readonly injector = inject(Injector);
  private readonly isBrowser = isPlatformBrowser(inject(PLATFORM_ID));
  protected readonly thread = inject(AskAinThreadService);

  private readonly composer = viewChild<ElementRef<HTMLInputElement>>('composer');
  private readonly threadEl = viewChild<ElementRef<HTMLElement>>('threadLog');

  readonly query = signal('');

  /** Prompt chips (i18n keys) shown on the empty state. */
  readonly suggestions = ['askAin.chips.wedding', 'askAin.chips.eidGift', 'askAin.chips.casualBeige'];

  /**
   * Id of the newest turn when the page opened: a restored thread is not
   * re-announced on arrival, only answers that land while the page is open.
   */
  private readonly initialLastTurnId = this.thread.turns().at(-1)?.id ?? null;

  /**
   * The single short line screen readers hear (the visually hidden polite
   * live region): "Ain is typing…" while waiting, then just Ain's new reply
   * (or its fallback / error text) — never the product grid or the
   * customer's own message.
   */
  readonly announcement = computed<{ kind: 'none' | 'text' | 'key'; text: string }>(() => {
    if (this.thread.pending()) {
      return { kind: 'key', text: 'askAin.typing' };
    }
    const last = this.thread.turns().at(-1);
    if (!last || last.role !== 'ain' || last.id === this.initialLastTurnId) {
      return { kind: 'none', text: '' };
    }
    if (last.status === 'error') {
      return { kind: 'key', text: 'askAin.error' };
    }
    if (last.reply.trim()) {
      return { kind: 'text', text: last.reply };
    }
    return {
      kind: 'key',
      text: last.cards.length
        ? 'askAin.resultsIntro'
        : last.needsClarification
          ? 'askAin.clarifyFallback'
          : 'askAin.noResults',
    };
  });

  /** Whether the "Chat with Ain on WhatsApp" entry point is configured. */
  protected readonly ainWhatsappEnabled = AIN_WHATSAPP_NUMBER !== '';

  constructor() {
    // Keep the newest message in view as the thread grows / Ain starts typing.
    effect(() => {
      const count = this.thread.turns().length;
      const pending = this.thread.pending();
      if (!this.isBrowser || (count === 0 && !pending)) return;
      afterNextRender(() => this.scrollToLatest(), { injector: this.injector });
    });
  }

  /** wa.me link to Ain with a localized pre-filled greeting. */
  protected ainWhatsappHref(): string {
    return ainWhatsappUrl(this.translate.instant('askAin.whatsappGreeting'));
  }

  ngOnInit(): void {
    this.concierge.recordEvent('ai_opened');

    // Deep link (?q=…): start a fresh conversation with that message, then drop
    // the param so coming back from a product doesn't re-send it.
    const initial = (this.route.snapshot?.queryParamMap?.get('q') ?? '').trim();
    if (initial) {
      this.thread.newChat();
      void this.thread.send(initial);
      void this.router.navigate([], {
        relativeTo: this.route,
        queryParams: { q: null },
        queryParamsHandling: 'merge',
        replaceUrl: true,
      });
    }
  }

  submit(): void {
    const text = this.query();
    if (!text.trim() || this.thread.pending()) {
      return;
    }
    this.query.set('');
    void this.thread.send(text);
    this.focusComposer();
  }

  useSuggestion(key: string): void {
    this.sendText(this.translate.instant(key));
  }

  /** A follow-up chip sends its text as the customer's next message. */
  useFollowUp(text: string): void {
    this.sendText(text);
  }

  retry(): void {
    void this.thread.retry();
    this.focusComposer();
  }

  newChat(): void {
    this.thread.newChat();
    this.query.set('');
    this.focusComposer();
  }

  /** Card anchor navigates itself; we just beacon the click (best-effort). */
  onCardClick(turn: AinTurn, card: ConciergeCard): void {
    this.concierge.recordEvent('ai_product_clicked', {
      ...(turn.interactionId ? { interaction_id: turn.interactionId } : {}),
      product_id: card.id,
    });
  }

  private sendText(text: string): void {
    if (this.thread.pending()) return;
    void this.thread.send(text);
    this.focusComposer();
  }

  private focusComposer(): void {
    this.composer()?.nativeElement.focus();
  }

  private scrollToLatest(): void {
    const log = this.threadEl()?.nativeElement;
    const last = log?.lastElementChild as HTMLElement | null | undefined;
    if (last && typeof last.scrollIntoView === 'function') {
      const reduce =
        typeof window !== 'undefined' &&
        typeof window.matchMedia === 'function' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches;
      last.scrollIntoView({ block: 'nearest', behavior: reduce ? 'auto' : 'smooth' });
    }
  }
}
