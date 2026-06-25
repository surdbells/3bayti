import { Component, ChangeDetectionStrategy, Input } from '@angular/core';
import { SectionHeaderComponent } from '../../shared/ui/section-header';
import { SkeletonShimmerComponent } from '../../shared/ui/skeleton-shimmer';
import { ProductCardComponent } from '../catalog/product-card';
import { TranslatePipe } from '@ngx-translate/core';
import type { Product } from '../catalog/product.model';

/**
 * TopSellers — the best-sellers rail with a ranked treatment. Unlike a
 * plain product strip, each card carries its position (1, 2, 3 …): the
 * numbering is meaningful here because the row genuinely is a ranking
 * (most-loved first), not decoration.
 *
 * Reuses ProductCard for the card itself; adds an editorial rank numeral
 * above each. Hides nothing here — the parent omits the section when the
 * list is empty.
 */
@Component({
  selector: 'home-top-sellers',
  standalone: true,
  imports: [SectionHeaderComponent, SkeletonShimmerComponent, ProductCardComponent, TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <section class="home-section" [attr.aria-label]="'home.topSellers.aria' | translate">
      <div class="home-section__bounds">
        <ui-section-header
          [eyebrow]="'home.topSellers.eyebrow' | translate"
          [title]="'home.topSellers.title' | translate"
          [ctaLabel]="'home.topSellers.viewAll' | translate"
          [ctaLink]="viewAllUrl"
        />

        <div class="ts-rail" role="list">
          @if (loading) {
            @for (i of skeletons; track i) {
              <div class="ts-item ts-item--skeleton" aria-hidden="true">
                <span class="ts-rank ts-rank--ghost"></span>
                <ui-skeleton-shimmer aspectRatio="3 / 4" [rounded]="false" />
              </div>
            }
          } @else {
            @for (p of products; track p.id; let idx = $index) {
              <div class="ts-item" role="listitem">
                <span class="ts-rank" aria-hidden="true">{{ idx + 1 }}</span>
                <ui-product-card [product]="p" />
              </div>
            }
          }
        </div>
      </div>
    </section>
  `,
  styles: [`
    :host { display: block; }

    /* .home-section's gutter lives in home.scss, which is encapsulated to
       HomeComponent — it doesn't reach this separate component's DOM, so
       the inline gutter is declared here too. */
    .home-section {
      padding-inline: var(--page-padding-x);
    }
    .home-section__bounds {
      max-width: var(--page-max-width);
      margin-inline: auto;
    }

    .ts-rail {
      display: flex;
      gap: var(--space-md);
      overflow-x: auto;
      overflow-y: visible;
      padding-block: var(--space-2xs) var(--space-sm);
      scroll-snap-type: x proximity;
      scrollbar-width: none;
      -ms-overflow-style: none;
    }
    .ts-rail::-webkit-scrollbar { display: none; }

    .ts-item {
      flex: 0 0 auto;
      width: 180px;
      scroll-snap-align: start;
    }
    @media (min-width: 1280px) {
      .ts-item { width: 216px; }
    }

    /* GOLDEN HOUR: espresso rank numeral with a gold tick-rule so the
       ranking reads as a confident, festive index. */
    .ts-rank {
      display: flex;
      align-items: baseline;
      gap: var(--space-xs);
      font-family: 'Playfair Display', Georgia, 'Times New Roman', serif;
      font-weight: 700;
      font-size: 1.9rem;
      line-height: 1;
      color: var(--color-brand-700, #2e241c); /* espresso */
      margin-block-end: var(--space-xs);
      padding-inline-start: 2px;
    }
    .ts-rank::after {
      content: "";
      flex: 1;
      height: 2px;
      background: linear-gradient(90deg, var(--color-brand-300, #d6a93b), transparent);
      transform: translateY(-6px);
    }
    /* RTL: fade the tick-rule from the leading (right) edge. */
    :host-context([dir='rtl']) .ts-rank::after {
      background: linear-gradient(270deg, var(--color-brand-300, #d6a93b), transparent);
    }
    .ts-rank--ghost {
      min-height: 1.9rem;
    }
    .ts-rank--ghost::after { display: none; }

    .ts-item ui-product-card {
      display: block;
      width: 100%;
    }
  `],
})
export class TopSellersComponent {
  @Input() products: Product[] = [];
  @Input() loading = false;
  @Input() viewAllUrl = '/best-sellers';

  readonly skeletons = [0, 1, 2, 3, 4];
}
