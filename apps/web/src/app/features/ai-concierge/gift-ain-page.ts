import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { ProductCardComponent } from '../catalog/product-card';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import { ConciergeService, type ConciergeCard, type GiftBriefInput, type GiftCardSuggestion } from './concierge.service';

interface BudgetBand {
  key: string;
  min?: number;
  max?: number;
}

/**
 * "Ask Ain — find a gift": a guided gifting flow. The shopper picks recipient /
 * occasion / budget (+ optional colour, style, size); Ain returns real, in-stock
 * gift ideas via the same retrieval engine, and — when the gift is risky
 * (unknown size or few matches) — a "buy a gift card instead" nudge into the
 * existing gift-card flow. All navigation is slug-based (via ui-product-card).
 */
@Component({
  selector: 'app-gift-ain',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [FormsModule, TranslatePipe, ProductCardComponent],
  templateUrl: './gift-ain-page.html',
  styleUrl: './gift-ain-page.scss',
})
export class GiftAinPageComponent implements OnInit {
  private readonly concierge = inject(ConciergeService);
  private readonly analytics = inject(AnalyticsService);
  private readonly router = inject(Router);

  readonly recipients = ['sister', 'mother', 'friend', 'wife', 'daughter', 'colleague'];
  readonly occasions = ['eid', 'wedding', 'birthday', 'graduation', 'anniversary', 'justBecause'];
  readonly budgetBands: BudgetBand[] = [
    { key: 'under200', max: 200 },
    { key: '200to500', min: 200, max: 500 },
    { key: '500to1000', min: 500, max: 1000 },
    { key: 'over1000', min: 1000 },
  ];

  readonly recipient = signal('');
  readonly occasion = signal('');
  readonly budget = signal<BudgetBand | null>(null);
  readonly coloursText = signal('');
  readonly stylesText = signal('');
  readonly size = signal('');

  readonly cards = signal<ConciergeCard[]>([]);
  readonly giftCard = signal<GiftCardSuggestion | null>(null);
  readonly loading = signal(false);
  readonly hasSearched = signal(false);

  private interactionId: number | null = null;

  readonly canSubmit = computed(
    () => !!this.occasion() || !!this.budget() || !!this.coloursText().trim() || !!this.stylesText().trim(),
  );
  readonly isEmpty = computed(() => this.hasSearched() && !this.loading() && this.cards().length === 0 && !this.giftCard());

  ngOnInit(): void {
    this.concierge.recordEvent('ai_opened', { feature: 'gift_concierge' });
  }

  pickRecipient(r: string): void {
    this.recipient.set(this.recipient() === r ? '' : r);
  }
  pickOccasion(o: string): void {
    this.occasion.set(this.occasion() === o ? '' : o);
  }
  pickBudget(b: BudgetBand): void {
    this.budget.set(this.budget()?.key === b.key ? null : b);
  }

  private toList(text: string): string[] {
    return text
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s.length > 0);
  }

  async submit(): Promise<void> {
    if (!this.canSubmit() || this.loading()) {
      return;
    }
    const band = this.budget();
    const brief: GiftBriefInput = {
      recipient: this.recipient() || undefined,
      occasion: this.occasion() || undefined,
      colours: this.toList(this.coloursText()),
      styles: this.toList(this.stylesText()),
      size: this.size().trim() || undefined,
      budget_min: band?.min,
      budget_max: band?.max,
    };

    this.loading.set(true);
    this.hasSearched.set(true);
    this.cards.set([]);
    this.giftCard.set(null);

    try {
      const res = await this.concierge.askGift(brief);
      this.interactionId = res.interactionId;
      this.cards.set(res.cards);
      this.giftCard.set(res.giftCard);
    } catch {
      this.cards.set([]);
      this.giftCard.set(null);
    } finally {
      this.loading.set(false);
    }
  }

  onCardClick(card: ConciergeCard): void {
    this.concierge.recordEvent('ai_product_clicked', {
      ...(this.interactionId ? { interaction_id: this.interactionId } : {}),
      product_id: card.id,
    });
  }

  /** The gift-card nudge routes into the existing gift-card purchase flow. */
  openGiftCards(): void {
    this.analytics.event('ai_gift_card_cta_click', { denomination: this.giftCard()?.suggested_denomination ?? '' });
    void this.router.navigateByUrl('/gift-cards');
  }
}
