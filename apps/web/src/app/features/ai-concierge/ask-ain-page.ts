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
import { CONCIERGE_MAX_QUESTIONS, ConciergeService, isShowNowChip, type ConciergeCard } from './concierge.service';
import { AskAinThreadService, type AinTurn } from './ask-ain-thread.service';
import { AIN_WHATSAPP_NUMBER, ainWhatsappUrl } from '../../core/config/support.constants';

/** A tappable chip under Ain's newest turn. `skip` = "Show me what you have". */
export interface AinChip {
  text: string;
  skip: boolean;
}

/** A titled group of empty-state starter prompts (i18n keys). */
export interface StarterGroup {
  id: string;
  title: string;
  chips: string[];
}

/** Empty-state starter prompts, grouped. Tapping one sends its localized text. */
export const STARTER_GROUPS: readonly StarterGroup[] = [
  {
    id: 'occasions',
    title: 'askAin.starters.occasions.title',
    chips: [
      'askAin.starters.occasions.wedding',
      'askAin.starters.occasions.eid',
      'askAin.starters.occasions.ramadan',
      'askAin.starters.occasions.work',
      'askAin.starters.occasions.evening',
    ],
  },
  {
    id: 'gifts',
    title: 'askAin.starters.gifts.title',
    chips: [
      'askAin.starters.gifts.sister',
      'askAin.starters.gifts.mother',
      'askAin.starters.gifts.friend',
      'askAin.starters.gifts.bride',
    ],
  },
  {
    id: 'styles',
    title: 'askAin.starters.styles.title',
    chips: [
      'askAin.starters.styles.casualBeige',
      'askAin.starters.styles.classicBlack',
      'askAin.starters.styles.pastel',
      'askAin.starters.styles.embellished',
      'askAin.starters.styles.minimal',
    ],
  },
];

/**
 * "Ask Ain" — 3bayti's AI style & gifting concierge (web), as a chat thread.
 *
 * Each customer message is answered by Ain with a short reply, REAL in-stock
 * ranked products (each with Ain's one-line reason, rendered with the shared
 * ui-product-card) and tappable follow-up suggestions. The last 8 turns (text
 * only) go back to the API as history so refinements like "cheaper" or "in
 * black" keep the earlier constraints. The thread lives in a root service
 * (+ sessionStorage), so it survives opening a product and coming back.
 * Guided discovery: the server may answer with ONE question at a time (a
 * question_slot + answer chips, no products) before showing results; the
 * page echoes the last response's asked_slots on every send and sends
 * show_now when the customer taps "Show me what you have".
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

  /** Grouped starter prompts (i18n keys) shown on the empty state. */
  readonly starterGroups = STARTER_GROUPS;

  /** Upper bound shown in the "Question n of up to 4" hint. */
  readonly maxQuestions = CONCIERGE_MAX_QUESTIONS;

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
    return { kind: 'key', text: this.fallbackKey(last) };
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

  /**
   * i18n key for Ain's bubble when the reply text is empty: the slot's own
   * question on a guided turn, otherwise the results / clarify / no-results line.
   */
  fallbackKey(turn: AinTurn): string {
    if (turn.questionSlot) {
      return 'askAin.questions.' + turn.questionSlot;
    }
    return turn.cards.length
      ? 'askAin.resultsIntro'
      : turn.needsClarification
        ? 'askAin.clarifyFallback'
        : 'askAin.noResults';
  }

  /** A guided-question turn (answer chips + progress hint, no products). */
  isQuestion(turn: AinTurn): boolean {
    return turn.questionSlot !== null && turn.cards.length === 0;
  }

  /** "Question n" for the progress hint (n = slots asked so far, this one included). */
  questionNumber(turn: AinTurn): number {
    return Math.min(Math.max(turn.askedSlots.length, 1), this.maxQuestions);
  }

  /**
   * The chips under Ain's turn. On a guided question the skip chip ("Show me
   * what you have") is moved last and styled apart; if the server didn't send
   * one, the page adds its own so the customer can always skip ahead.
   */
  chipsFor(turn: AinTurn): AinChip[] {
    const label = this.translate.instant('askAin.showNow') as string;
    const chips: AinChip[] = turn.followUps.map((text) => ({ text, skip: isShowNowChip(text, label) }));
    if (!this.isQuestion(turn)) {
      return chips;
    }
    const answers = chips.filter((c) => !c.skip);
    const skip = chips.find((c) => c.skip) ?? (label && label !== 'askAin.showNow' ? { text: label, skip: true } : null);
    return skip ? [...answers, skip] : answers;
  }

  /** An answer / refine chip sends its text; the skip chip also sends show_now. */
  useChip(chip: AinChip): void {
    this.sendText(chip.text, chip.skip);
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

  private sendText(text: string, showNow = false): void {
    if (this.thread.pending()) return;
    void this.thread.send(text, showNow ? { showNow: true } : {});
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
