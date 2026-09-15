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
    justBecause: ['M12 3l1.9 4.8L18.7 9.7 13.9 11.6 12 16.4 10.1 11.6 5.3 9.7 10.1 7.8z', 'M19 15l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z'],
  };

  recipientIcon(key: string): string[] {
    return this.recipientIcons[key] ?? [];
  }
  occasionIcon(key: string): string[] {
    return this.occasionIcons[key] ?? [];
  }

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
