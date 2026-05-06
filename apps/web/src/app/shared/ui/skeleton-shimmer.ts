import { Component, ChangeDetectionStrategy, Input } from '@angular/core';

/**
 * SkeletonShimmer — loading placeholder with a subtle shimmer sweep.
 *
 * Used wherever data is loading and we want to show a shape rather than
 * a blank space. Particularly useful for product strips on the home
 * page (Phase 1) — render 4 skeleton cards while the API call is in
 * flight, then swap to real cards when data arrives. Prevents the
 * jarring "nothing → something" pop and reduces perceived load time.
 *
 * Visual: a cream-tinted rectangle with a slow diagonal gradient sweep
 * (linear-gradient that animates from -100% to 100% over 1.6s, infinite
 * loop). Matches the Gilded Boutique palette — never grey-on-white,
 * always brand-tinted.
 *
 * Accessibility:
 *   - role="status" announces to screen readers as a live region
 *   - aria-label="Loading" gives non-visual users meaningful context
 *   - prefers-reduced-motion disables the sweep animation
 *
 * Usage:
 *   <ui-skeleton-shimmer />                                  basic block
 *   <ui-skeleton-shimmer [aspectRatio]="'3 / 4'" />          card image
 *   <ui-skeleton-shimmer height="14px" width="60%" />        text line
 *   <ui-skeleton-shimmer [rounded]="false" />                no radius
 */
@Component({
  selector: 'ui-skeleton-shimmer',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="skeleton"
      [class.skeleton--rounded]="rounded"
      [style.width]="width"
      [style.height]="height"
      [style.aspect-ratio]="aspectRatio"
      role="status"
      aria-label="Loading"
    >
      <div class="skeleton__sweep" aria-hidden="true"></div>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      width: 100%;
    }

    .skeleton {
      position: relative;
      overflow: hidden;
      width: 100%;
      height: 100%;
      /* Cream-tinted base, slightly darker than card surface so the
       * skeleton reads as "absent content" rather than "empty card". */
      background: var(--color-bg-muted);
    }

    .skeleton--rounded {
      border-radius: 14px;
    }

    .skeleton__sweep {
      position: absolute;
      inset: 0;
      /* The sweep is a soft cream-to-transparent gradient that travels
       * left-to-right. Brand-tinted so it harmonises with the cream
       * surface — neutral grey would look out of place. */
      background: linear-gradient(
        110deg,
        transparent 30%,
        rgba(253, 250, 243, 0.7) 50%,
        transparent 70%
      );
      transform: translateX(-100%);
      animation: skeleton-sweep 1.6s ease-in-out infinite;
    }

    @keyframes skeleton-sweep {
      to {
        transform: translateX(100%);
      }
    }

    /* Honour reduced-motion preferences. The skeleton still renders
     * (so users know content is loading) but the animation stops. */
    @media (prefers-reduced-motion: reduce) {
      .skeleton__sweep {
        animation: none;
      }
    }
  `],
})
export class SkeletonShimmerComponent {
  /** Width — any CSS length. Default fills container. */
  @Input() width = '100%';

  /** Height — any CSS length. Use with aspectRatio for image placeholders. */
  @Input() height = '100%';

  /**
   * Aspect ratio — CSS value like '3 / 4' or '16 / 9'. When set, the
   * height is determined by width × ratio, which is exactly what we
   * want for product-card image placeholders.
   */
  @Input() aspectRatio: string | null = null;

  /** Whether to apply the standard 14px border-radius. */
  @Input() rounded = true;
}
