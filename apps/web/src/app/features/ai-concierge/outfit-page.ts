import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { Router } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';

import { ProductCardComponent } from '../catalog/product-card';
import { AnalyticsService } from '../../core/monitoring/analytics.service';
import { AuthService } from '../../core/auth/auth.service';
import { CartService } from '../../core/cart/cart.service';
import { ToastService } from '../../shared/forms';
import { StyleService } from '../styles/style.service';
import {
  ConciergeService,
  type OutfitBriefInput,
  type OutfitPieceCard,
  type GiftCardSuggestion,
} from './concierge.service';

interface BudgetBand {
  key: string;
  min?: number;
  max?: number;
}

/**
 * "Style me with Ain": a guided outfit generator. The shopper picks an occasion
 * (+ optional style, colour, budget and hero garment); Ain composes a
 * coordinated multi-piece look — a hero garment plus complementary pieces — from
 * real, in-stock products, with "add the look to bag" and "save the look" (a
 * signed-in Style). A gift-card nudge is offered when no coherent look fits.
 */
@Component({
  selector: 'app-outfit',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  imports: [TranslatePipe, ProductCardComponent],
  templateUrl: './outfit-page.html',
  styleUrl: './outfit-page.scss',
})
export class OutfitPageComponent implements OnInit {
  private readonly concierge = inject(ConciergeService);
  private readonly analytics = inject(AnalyticsService);
  private readonly router = inject(Router);
  private readonly auth = inject(AuthService);
  private readonly cart = inject(CartService);
  private readonly styles = inject(StyleService);
  private readonly toast = inject(ToastService);

  readonly isAuthenticated = this.auth.isAuthenticated;

  readonly occasions = ['eid', 'wedding', 'party', 'graduation', 'ramadan', 'everyday'];
  readonly styleOptions = ['elegant', 'casual', 'traditional', 'modern', 'minimal', 'embellished'];
  readonly heroTypes = ['abaya', 'kaftan', 'dress'];
  readonly colourOptions = ['black', 'beige', 'rose', 'gold', 'navy', 'white', 'green', 'grey'];
  readonly budgetBands: BudgetBand[] = [
    { key: 'under300', max: 300 },
    { key: '300to700', min: 300, max: 700 },
    { key: '700to1500', min: 700, max: 1500 },
    { key: 'over1500', min: 1500 },
  ];

  private readonly colourSwatch: Record<string, string> = {
    black: '#2e241c', beige: '#e7d6bc', rose: '#c98a8a', gold: '#b18f1f',
    navy: '#2b3a55', white: '#f4efe7', green: '#4a6350', grey: '#9a938a',
  };

  /** Stroke-only SVG path data (24×24, currentColor) for each occasion icon. */
  private readonly occasionIcons: Record<string, string[]> = {
    eid: ['M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z'],
    wedding: ['M14 9a5 5 0 1 1-10 0 5 5 0 0 1 10 0', 'M20 15a5 5 0 1 1-10 0 5 5 0 0 1 10 0'],
    party: ['M12 3l1.9 4.8L18.7 9.7 13.9 11.6 12 16.4 10.1 11.6 5.3 9.7 10.1 7.8z', 'M19 15l.6 1.6 1.6.6-1.6.6-.6 1.6-.6-1.6-1.6-.6 1.6-.6z'],
    graduation: ['M22 10 12 5 2 10l10 5 10-5z', 'M6 12v5c0 1 2.5 3 6 3s6-2 6-3v-5', 'M22 10v6'],
    ramadan: ['M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z', 'M17 4l.6 1.6L19 6l-1.4.4L17 8l-.6-1.6L15 6l1.4-.4z'],
    everyday: ['M12 3v2M12 19v2M5 12H3M21 12h-2M6.3 6.3 4.9 4.9M19.1 19.1l-1.4-1.4M6.3 17.7l-1.4 1.4M19.1 4.9l-1.4 1.4', 'M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z'],
  };

  occasionIcon(key: string): string[] {
    return this.occasionIcons[key] ?? [];
  }

  readonly occasion = signal('');
  readonly heroType = signal('');
  readonly budget = signal<BudgetBand | null>(null);
  readonly selectedStyles = signal<string[]>([]);
  readonly selectedColours = signal<string[]>([]);

  readonly pieces = signal<OutfitPieceCard[]>([]);
  readonly rationale = signal('');
  readonly totalPrice = signal<{ amount: number; currency: string } | null>(null);
  readonly giftCard = signal<GiftCardSuggestion | null>(null);
  readonly loading = signal(false);
  readonly hasSearched = signal(false);
  readonly adding = signal(false);
  readonly saving = signal(false);
  readonly saved = signal(false);

  private interactionId: number | null = null;

  readonly canSubmit = computed(
    () => !!this.occasion() || this.selectedStyles().length > 0 || this.selectedColours().length > 0 || !!this.budget() || !!this.heroType(),
  );
  readonly isEmpty = computed(() => this.hasSearched() && !this.loading() && this.pieces().length === 0 && !this.giftCard());

  ngOnInit(): void {
    this.concierge.recordEvent('ai_opened', { feature: 'outfit' });
  }

  colourHex(name: string): string {
    return this.colourSwatch[name] ?? '#cbb79a';
  }

  pickOccasion(o: string): void {
    this.occasion.set(this.occasion() === o ? '' : o);
  }
  pickHero(h: string): void {
    this.heroType.set(this.heroType() === h ? '' : h);
  }
  pickBudget(b: BudgetBand): void {
    this.budget.set(this.budget()?.key === b.key ? null : b);
  }
  toggleStyle(s: string): void {
    this.selectedStyles.update((list) => (list.includes(s) ? list.filter((x) => x !== s) : [...list, s]));
  }
  toggleColour(c: string): void {
    this.selectedColours.update((list) => (list.includes(c) ? list.filter((x) => x !== c) : [...list, c]));
  }
  isStyleOn(s: string): boolean {
    return this.selectedStyles().includes(s);
  }
  isColourOn(c: string): boolean {
    return this.selectedColours().includes(c);
  }

  generate(): void {
    if (!this.canSubmit() || this.loading()) {
      return;
    }
    const band = this.budget();
    const brief: OutfitBriefInput = {
      occasion: this.occasion() || undefined,
      styles: this.selectedStyles(),
      colours: this.selectedColours(),
      product_type: this.heroType() || undefined,
      budget_min: band?.min,
      budget_max: band?.max,
    };
    void this.run(brief);
  }

  private async run(brief: OutfitBriefInput): Promise<void> {
    this.loading.set(true);
    this.hasSearched.set(true);
    this.saved.set(false);
    this.pieces.set([]);
    this.rationale.set('');
    this.giftCard.set(null);
    this.totalPrice.set(null);

    try {
      const res = await this.concierge.generateOutfit(brief);
      this.interactionId = res.interactionId;
      this.pieces.set(res.pieces);
      this.rationale.set(res.rationale);
      this.totalPrice.set(res.totalPrice);
      this.giftCard.set(res.giftCard);
    } catch {
      this.pieces.set([]);
      this.giftCard.set(null);
    } finally {
      this.loading.set(false);
    }
  }

  onPieceClick(piece: OutfitPieceCard): void {
    this.concierge.recordEvent('ai_product_clicked', {
      ...(this.interactionId ? { interaction_id: this.interactionId } : {}),
      product_id: piece.product.id,
    });
  }

  /** Add every piece of the look to the bag. */
  async addLook(): Promise<void> {
    const pieces = this.pieces();
    if (pieces.length === 0 || this.adding()) {
      return;
    }
    this.adding.set(true);
    let added = 0;
    for (const piece of pieces) {
      try {
        await this.cart.addItem({ product_id: piece.product.id, quantity: 1, size: null, color: null, is_custom: false });
        added++;
        this.concierge.recordEvent('ai_outfit_item_added', {
          ...(this.interactionId ? { interaction_id: this.interactionId } : {}),
          product_id: piece.product.id,
        });
      } catch {
        // Skip a piece that went out of stock between generation and add.
      }
    }
    this.adding.set(false);
    this.concierge.recordEvent('ai_outfit_added', { count: added });
    if (added > 0) {
      this.toast.success('outfit.addedToast', { count: added });
    } else {
      this.toast.error('outfit.addFailedToast');
    }
  }

  /** Save the composed look as an Ain Style (signed-in only, <= 4 pieces). */
  async saveLook(): Promise<void> {
    if (!this.isAuthenticated()) {
      void this.router.navigateByUrl('/login?returnUrl=/outfit');
      return;
    }
    const pieces = this.pieces();
    if (pieces.length === 0 || this.saving()) {
      return;
    }
    this.saving.set(true);
    try {
      await this.styles.createStyle({
        name: this.lookName(),
        products: pieces.slice(0, 4).map((p) => p.product.id),
        source: 'ai',
        prompt: this.promptSummary(),
        rationale: this.rationale() || undefined,
      });
      this.saved.set(true);
      this.concierge.recordEvent('ai_outfit_saved', { count: pieces.length });
      this.toast.success('outfit.savedToast');
    } catch {
      this.toast.error('outfit.saveFailedToast');
    } finally {
      this.saving.set(false);
    }
  }

  openGiftCards(): void {
    this.analytics.event('ai_gift_card_cta_click', { denomination: this.giftCard()?.suggested_denomination ?? '' });
    this.concierge.recordEvent('ai_gift_card_recommended', { context: 'outfit', surface: 'web' });
    void this.router.navigateByUrl('/gift-cards');
  }

  private lookName(): string {
    const parts = [this.selectedStyles()[0], this.occasion()].filter((s) => !!s);
    const label = parts.join(' ').trim();
    return label !== '' ? `${label} look`.replace(/^\w/, (c) => c.toUpperCase()) : 'My Ain outfit';
  }

  private promptSummary(): string {
    const b: string[] = [];
    if (this.occasion()) b.push(this.occasion());
    if (this.selectedStyles().length) b.push(this.selectedStyles().join(', '));
    if (this.selectedColours().length) b.push('in ' + this.selectedColours().join(', '));
    if (this.heroType()) b.push('around a ' + this.heroType());
    return b.join(' · ');
  }
}
