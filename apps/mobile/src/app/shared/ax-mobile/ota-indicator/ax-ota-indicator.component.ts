import {
  ChangeDetectionStrategy,
  Component,
  OnDestroy,
  computed,
  effect,
  inject,
  signal,
} from '@angular/core';
import { TranslatePipe } from '../../../translate.pipe';
import { OtaUpdateService } from '../../../core/services/ota-update.service';
import { CheckoutActivityService } from '../../../core/services/checkout-activity.service';

/**
 * AxOtaIndicatorComponent, the MANDATORY OTA update sheet.
 *
 * OTA updates are treated as required: the download runs automatically (no
 * approval), the customer just has to be aware it's happening and can't bypass
 * it. So this is a full-screen blocking sheet, no dismiss, no "later":
 *   - downloading → title + description + live progress bar (percent + ETA)
 *   - ready       → a branded "what's new" panel showing the release summary,
 *                   a "Restart now" button, and a visible 6-second countdown
 *                   before it auto-restarts into the new bundle. The countdown
 *                   (was a 1.2s beat) gives the customer time to read what's new.
 *
 * The scrim blocks interaction with the app behind it. Hardware-back isn't
 * trapped on purpose: backing out just exits the app, and the freshly
 * downloaded bundle applies on the next cold start anyway, so the update can't
 * actually be skipped.
 *
 * Purely reactive off OtaUpdateService; shows for both the automatic
 * resume-time download and any on-demand dashboard check.
 */
@Component({
  selector: 'ax-ota-indicator',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (visible()) {
      <div class="ota-sheet-scrim"></div>
      <section class="ota-sheet" role="alertdialog" aria-live="assertive" aria-modal="true"
               [attr.aria-label]="titleKey() | translate">
        <span class="ota-sheet__grip" aria-hidden="true"></span>

        @if (status() === 'ready') {
          <div class="ota-sheet__whatsnew">
            <span class="ota-sheet__brandmark">3bayti</span>
            @if (version()) {
              <span class="ota-sheet__version">{{ 'ota_update_version' | translate }} {{ version() }}</span>
            }
          </div>
          <div class="ota-sheet__body">
            <h2 class="ota-sheet__title">{{ titleKey() | translate }}</h2>
            @if (summary()) {
              <p class="ota-sheet__notes-label">{{ 'ota_update_whats_new' | translate }}</p>
              <div class="ota-sheet__notes-scroll">
                @if (summaryLines().length > 1) {
                  <ul class="ota-sheet__notes-list">
                    @for (line of summaryLines(); track $index) {
                      <li>{{ line }}</li>
                    }
                  </ul>
                } @else {
                  <p class="ota-sheet__notes">{{ summary() }}</p>
                }
              </div>
            } @else {
              <p class="ota-sheet__desc">{{ descKey() | translate }}</p>
            }
          </div>
          <div class="ota-sheet__footer">
            <button type="button" class="ota-sheet__primary" (click)="restart()">
              {{ 'ota_update_restart' | translate }}
            </button>
            <p class="ota-sheet__countdown" aria-live="polite">
              {{ 'ota_update_auto_restart' | translate: { seconds: countdown() } }}
            </p>
          </div>
        } @else {
          <div class="ota-sheet__body">
            <h2 class="ota-sheet__title">{{ titleKey() | translate }}</h2>
            <p class="ota-sheet__desc">{{ descKey() | translate }}</p>
            <div class="ota-sheet__progress">
              <div class="ota-sheet__track">
                <div class="ota-sheet__fill" [style.width.%]="percent()"></div>
              </div>
              <div class="ota-sheet__meta">
                <span class="ota-sheet__pct">{{ percent() }}%</span>
                @if (etaLabel()) {
                  <span class="ota-sheet__eta">{{ etaLabel() }}</span>
                }
              </div>
            </div>
          </div>
        }
      </section>
    }
  `,
  styleUrl: './ax-ota-indicator.component.scss',
})
export class AxOtaIndicatorComponent implements OnDestroy {
  private readonly ota = inject(OtaUpdateService);
  private readonly checkout = inject(CheckoutActivityService);

  protected readonly status = this.ota.status;
  protected readonly percent = this.ota.percent;
  protected readonly summary = this.ota.summary;
  protected readonly version = this.ota.version;

  /**
   * The summary split into clean lines (leading bullet markers stripped) for
   * proper left-aligned list rendering — centered bullet text read poorly.
   * More than one line renders as a bulleted list; a single line stays a
   * paragraph. Empty when there's no summary.
   */
  protected readonly summaryLines = computed(() => {
    const s = this.summary();
    if (!s) {
      return [];
    }
    return s
      .split(/\r?\n/)
      .map((line) => line.replace(/^\s*(?:[•·▪‣]\s*|[-*]\s+)/, '').trim())
      .filter((line) => line.length > 0);
  });

  /** Seconds shown, then counted down, before the ready bundle auto-applies. */
  private readonly RESTART_SECONDS = 6;
  protected readonly countdown = signal(this.RESTART_SECONDS);

  /**
   * Blocking sheet is up whenever a bundle is downloading or ready to apply —
   * BUT never while a checkout / payment is in progress. A full-screen OTA
   * sheet mid-payment would itself interrupt checkout, so we hold it back until
   * the checkout window closes (the bundle stays staged and applies then).
   */
  protected readonly visible = computed(
    () =>
      !this.checkout.isActive() &&
      (this.status() === 'downloading' || this.status() === 'ready'),
  );
  protected readonly titleKey = computed(() =>
    this.status() === 'ready' ? 'ota_update_ready_title' : 'ota_update_downloading_title',
  );
  protected readonly descKey = computed(() =>
    this.status() === 'ready' ? 'ota_update_ready_desc' : 'ota_update_downloading_desc',
  );
  /** ETA formatted "M:SS" (e.g. "0:06"), or empty when not yet estimable. */
  protected readonly etaLabel = computed(() => {
    const s = this.ota.etaSeconds();
    if (s === null || s <= 0) {
      return '';
    }
    const m = Math.floor(s / 60);
    const sec = String(s % 60).padStart(2, '0');
    return `${m}:${sec}`;
  });

  private restartTimer: ReturnType<typeof setInterval> | null = null;

  constructor() {
    effect(() => {
      const ready = this.status() === 'ready';
      const busy = this.checkout.isActive();
      // Ready + not mid-checkout → run the visible countdown, then auto-apply.
      // Anything else (still downloading, or a checkout opened mid-countdown)
      // pauses + resets it; the effect re-runs when those signals change and
      // restarts the countdown once we're ready + idle again.
      if (ready && !busy) {
        if (this.restartTimer === null) {
          this.countdown.set(this.RESTART_SECONDS);
          this.restartTimer = setInterval(() => {
            const next = this.countdown() - 1;
            this.countdown.set(Math.max(0, next));
            if (next <= 0) {
              this.clearTimer();
              void this.ota.restartToApply();
            }
          }, 1000);
        }
      } else {
        this.clearTimer();
        this.countdown.set(this.RESTART_SECONDS);
      }
    });
  }

  private clearTimer(): void {
    if (this.restartTimer !== null) {
      clearInterval(this.restartTimer);
      this.restartTimer = null;
    }
  }

  ngOnDestroy(): void {
    this.clearTimer();
  }

  protected restart(): void {
    this.clearTimer();
    void this.ota.restartToApply();
  }
}
