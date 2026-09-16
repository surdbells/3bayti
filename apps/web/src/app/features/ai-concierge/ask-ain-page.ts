import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { RouterLink } from '@angular/router';
import { TranslatePipe, TranslateService } from '@ngx-translate/core';

import { ProductCardComponent } from '../catalog/product-card';
import { ConciergeService, type ConciergeCard } from './concierge.service';

/**
 * "Ask Ain" — 3bayti's AI style & gifting concierge (web).
 *
 * A conversational input resolves a natural-language style/gift request to REAL,
 * in-stock, ranked products (each with Ain's one-line reason), rendered with the
 * shared ui-product-card. Works for guests; personalises when signed in (the
 * refresh interceptor attaches the token). All navigation is slug-based via the
 * card's own anchor — no legacy ids.
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

  readonly query = signal('');
  readonly cards = signal<ConciergeCard[]>([]);
  readonly loading = signal(false);
  readonly hasSearched = signal(false);
  readonly lastQuery = signal('');

  private interactionId: number | null = null;

  /** Prompt chips (i18n keys) shown on the empty state. */
  readonly suggestions = ['askAin.chips.wedding', 'askAin.chips.eidGift', 'askAin.chips.casualBeige'];

  readonly isEmpty = computed(() => this.hasSearched() && !this.loading() && this.cards().length === 0);

  ngOnInit(): void {
    this.concierge.recordEvent('ai_opened');
  }

  submit(): void {
    void this.ask(this.query());
  }

  useSuggestion(key: string): void {
    void this.ask(this.translate.instant(key));
  }

  async ask(text: string): Promise<void> {
    const q = (text ?? '').trim();
    if (!q || this.loading()) {
      return;
    }
    this.query.set('');
    this.lastQuery.set(q);
    this.loading.set(true);
    this.hasSearched.set(true);
    this.cards.set([]);

    try {
      const res = await this.concierge.ask(q);
      this.interactionId = res.interactionId;
      this.cards.set(res.cards);
    } catch {
      this.cards.set([]);
    } finally {
      this.loading.set(false);
    }
  }

  /** Card anchor navigates itself; we just beacon the click (best-effort). */
  onCardClick(card: ConciergeCard): void {
    this.concierge.recordEvent('ai_product_clicked', {
      ...(this.interactionId ? { interaction_id: this.interactionId } : {}),
      product_id: card.id,
    });
  }
}
