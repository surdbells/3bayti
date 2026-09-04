import {
  Component,
  ChangeDetectionStrategy,
  ElementRef,
  viewChild,
  signal,
  afterNextRender,
  DestroyRef,
  inject,
} from '@angular/core';
import { TranslatePipe } from '@ngx-translate/core';

/** One promo tile: the public asset path + its i18n alt-text key. */
interface Shot {
  readonly img: string;
  readonly altKey: string;
}

/**
 * AppShowcase, an auto-advancing, swipeable gallery of the mobile-app promo
 * tiles for the home "Get the app" band. Replaces the old decorative CSS
 * phone with the real store screenshots.
 *
 * Design
 * ------
 * - A CSS scroll-snap track: native touch swipe on mobile, no JS needed to
 *   scroll. The active tile scales up + brightens; neighbours peek at the
 *   edges so it reads as a browsable deck.
 * - Auto-advances every few seconds, but pauses on hover / focus / touch and
 *   when the tab is hidden, and never runs under prefers-reduced-motion.
 * - Dots + prev/next controls for explicit navigation; the active tile is
 *   tracked with an IntersectionObserver so manual swipes keep the dots in
 *   sync.
 *
 * Browser-only: all DOM wiring is inside afterNextRender, so it's inert during
 * any prerender/SSR pass and safe to server-render.
 *
 * The tiles are self-contained (they carry their own headline + branding), so
 * this component adds no text overlay — just accessible alt text per slide.
 */
@Component({
  selector: 'app-showcase',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div
      class="showcase"
      (mouseenter)="paused.set(true)"
      (mouseleave)="paused.set(false)"
      (focusin)="paused.set(true)"
      (focusout)="paused.set(false)"
      (touchstart)="paused.set(true)"
    >
      <div
        #track
        class="showcase__track"
        role="group"
        [attr.aria-roledescription]="'home.getApp.carousel' | translate"
        [attr.aria-label]="'home.getApp.showcaseAria' | translate"
      >
        @for (shot of shots; track shot.img; let i = $index) {
          <figure class="showcase__slide" [class.is-active]="i === active()">
            <img
              class="showcase__img"
              [src]="shot.img"
              [alt]="shot.altKey | translate"
              loading="lazy"
              decoding="async"
              width="1080"
              height="1920"
            />
          </figure>
        }
      </div>

      <button
        type="button"
        class="showcase__nav showcase__nav--prev"
        (click)="prev()"
        [attr.aria-label]="'home.getApp.prev' | translate"
      >
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M15 18l-6-6 6-6"/></svg>
      </button>
      <button
        type="button"
        class="showcase__nav showcase__nav--next"
        (click)="next()"
        [attr.aria-label]="'home.getApp.next' | translate"
      >
        <svg viewBox="0 0 24 24" width="20" height="20" aria-hidden="true" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 6l6 6-6 6"/></svg>
      </button>

      <div class="showcase__dots" role="tablist" [attr.aria-label]="'home.getApp.showcaseAria' | translate">
        @for (shot of shots; track shot.img; let i = $index) {
          <button
            type="button"
            class="showcase__dot"
            [class.is-active]="i === active()"
            role="tab"
            [attr.aria-selected]="i === active()"
            [attr.aria-label]="(shot.altKey | translate)"
            (click)="goTo(i)"
          ></button>
        }
      </div>
    </div>
  `,
  styles: [`
    :host {
      display: block;
      flex: 1 1 26rem;
      min-width: 0;
      align-self: center;
    }

    .showcase {
      position: relative;
      width: 100%;
    }

    .showcase__track {
      display: flex;
      gap: clamp(0.75rem, 2vw, 1.25rem);
      overflow-x: auto;
      scroll-snap-type: x mandatory;
      scroll-behavior: smooth;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: none;
      padding-block: 0.75rem;
      /* Symmetric inline padding so the first/last tiles can still centre. */
      padding-inline: clamp(1.5rem, 12%, 4rem);
    }
    .showcase__track::-webkit-scrollbar { display: none; }

    .showcase__slide {
      flex: 0 0 auto;
      width: clamp(190px, 60%, 250px);
      margin: 0;
      scroll-snap-align: center;
      border-radius: 22px;
      overflow: hidden;
      aspect-ratio: 9 / 16;
      background: linear-gradient(155deg, #efe6d6, #f6eedd);
      box-shadow: 0 12px 32px rgba(20, 14, 10, 0.30);
      border: 1px solid rgba(214, 169, 59, 0.30);
      transform: scale(0.9);
      opacity: 0.7;
      transition: transform 0.4s ease, opacity 0.4s ease, box-shadow 0.4s ease;
    }
    .showcase__slide.is-active {
      transform: scale(1);
      opacity: 1;
      box-shadow: 0 20px 46px rgba(20, 14, 10, 0.42);
    }

    .showcase__img {
      display: block;
      width: 100%;
      height: 100%;
      object-fit: cover;
    }

    .showcase__nav {
      position: absolute;
      inset-block-start: 50%;
      transform: translateY(-50%);
      z-index: 2;
      display: grid;
      place-items: center;
      width: 2.4rem;
      height: 2.4rem;
      border-radius: 50%;
      border: 1px solid rgba(214, 169, 59, 0.45);
      background: rgba(30, 20, 14, 0.62);
      color: var(--gh-cream, #fbf6ee);
      cursor: pointer;
      -webkit-backdrop-filter: blur(6px);
      backdrop-filter: blur(6px);
      transition: background 0.18s ease, transform 0.18s ease;
    }
    .showcase__nav:hover { background: rgba(30, 20, 14, 0.85); }
    .showcase__nav:focus-visible {
      outline: 2px solid var(--gh-gold, #d6a93b);
      outline-offset: 2px;
    }
    .showcase__nav--prev { inset-inline-start: -0.4rem; }
    .showcase__nav--next { inset-inline-end: -0.4rem; }
    /* RTL: the SVG chevrons are direction-agnostic glyphs, flip them so
       "prev" always points toward the start edge. */
    :host-context([dir="rtl"]) .showcase__nav svg { transform: scaleX(-1); }

    .showcase__dots {
      display: flex;
      justify-content: center;
      align-items: center;
      gap: 0.4rem;
      margin-block-start: 0.85rem;
    }
    .showcase__dot {
      width: 0.5rem;
      height: 0.5rem;
      padding: 0;
      border: none;
      border-radius: 999px;
      background: rgba(214, 169, 59, 0.35);
      cursor: pointer;
      transition: width 0.25s ease, background 0.25s ease;
    }
    .showcase__dot.is-active {
      width: 1.4rem;
      background: var(--gh-gold, #d6a93b);
    }
    .showcase__dot:focus-visible {
      outline: 2px solid var(--gh-gold, #d6a93b);
      outline-offset: 2px;
    }

    /* Touch viewports: lean on swipe + dots, hide the arrows. */
    @media (max-width: 720px) {
      .showcase__nav { display: none; }
    }

    @media (prefers-reduced-motion: reduce) {
      .showcase__track { scroll-behavior: auto; }
      .showcase__slide { transition: none; }
      .showcase__dot { transition: none; }
    }
  `],
})
export class AppShowcaseComponent {
  private readonly trackRef = viewChild.required<ElementRef<HTMLElement>>('track');
  private readonly destroyRef = inject(DestroyRef);

  /** Index of the tile currently centred in the viewport. */
  readonly active = signal(0);
  /** Auto-advance is suspended while the user is interacting. */
  readonly paused = signal(false);

  /**
   * The eight app-store promo tiles, in a browse-the-app order. Files live in
   * public/app-screenshots/ and are served from the site root at /app-screenshots/*.
   */
  readonly shots: readonly Shot[] = [
    { img: '/app-screenshots/home.png',        altKey: 'home.getApp.shots.home' },
    { img: '/app-screenshots/collections.png', altKey: 'home.getApp.shots.collections' },
    { img: '/app-screenshots/product.png',     altKey: 'home.getApp.shots.product' },
    { img: '/app-screenshots/style-hub.png',   altKey: 'home.getApp.shots.styleHub' },
    { img: '/app-screenshots/filters.png',     altKey: 'home.getApp.shots.filters' },
    { img: '/app-screenshots/gift-cards.png',  altKey: 'home.getApp.shots.giftCards' },
    { img: '/app-screenshots/checkout.png',    altKey: 'home.getApp.shots.checkout' },
    { img: '/app-screenshots/signin.png',      altKey: 'home.getApp.shots.signin' },
  ];

  private reduceMotion = false;

  constructor() {
    afterNextRender(() => {
      this.reduceMotion =
        typeof matchMedia === 'function' &&
        matchMedia('(prefers-reduced-motion: reduce)').matches;

      this.observeActiveSlide();
      this.startAutoAdvance();
    });
  }

  /** Keep `active` in sync with whichever slide is centred (manual swipes). */
  private observeActiveSlide(): void {
    const track = this.trackRef().nativeElement;
    const slides = Array.from(track.querySelectorAll<HTMLElement>('.showcase__slide'));
    if (slides.length === 0 || typeof IntersectionObserver === 'undefined') {
      return;
    }

    const observer = new IntersectionObserver(
      (entries) => {
        // Pick the most-visible slide as the active one.
        let best: IntersectionObserverEntry | null = null;
        for (const entry of entries) {
          if (!best || entry.intersectionRatio > best.intersectionRatio) {
            best = entry;
          }
        }
        if (best && best.isIntersecting) {
          const index = slides.indexOf(best.target as HTMLElement);
          if (index >= 0) {
            this.active.set(index);
          }
        }
      },
      { root: track, threshold: [0.5, 0.75, 1] },
    );

    slides.forEach((slide) => observer.observe(slide));
    this.destroyRef.onDestroy(() => observer.disconnect());
  }

  /** Advance one tile every few seconds unless paused / reduced-motion. */
  private startAutoAdvance(): void {
    if (this.reduceMotion) {
      return;
    }
    const timer = setInterval(() => {
      if (!this.paused() && !document.hidden) {
        this.next();
      }
    }, 4500);
    this.destroyRef.onDestroy(() => clearInterval(timer));
  }

  goTo(index: number): void {
    const track = this.trackRef().nativeElement;
    const slides = track.querySelectorAll<HTMLElement>('.showcase__slide');
    const target = slides[index];
    if (!target) {
      return;
    }
    // Centre the target within the track's viewport.
    const left = target.offsetLeft - (track.clientWidth - target.clientWidth) / 2;
    track.scrollTo({ left, behavior: this.reduceMotion ? 'auto' : 'smooth' });
    this.active.set(index);
  }

  next(): void {
    this.goTo((this.active() + 1) % this.shots.length);
  }

  prev(): void {
    this.goTo((this.active() - 1 + this.shots.length) % this.shots.length);
  }
}
