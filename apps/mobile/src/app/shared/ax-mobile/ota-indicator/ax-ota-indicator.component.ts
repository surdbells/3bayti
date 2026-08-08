import { ChangeDetectionStrategy, Component, computed, effect, inject } from '@angular/core';
import { TranslatePipe } from '../../../translate.pipe';
import { OtaUpdateService } from '../../../core/services/ota-update.service';

/**
 * AxOtaIndicatorComponent — the MANDATORY OTA update sheet.
 *
 * OTA updates are treated as required: the download runs automatically (no
 * approval), the customer just has to be aware it's happening and can't bypass
 * it. So this is a full-screen blocking sheet — no dismiss, no "later":
 *   - downloading → title + description + live progress bar (percent + ETA)
 *   - ready       → auto-restarts into the new bundle (reload()) after a brief
 *                   beat, with an immediate "Restart now" button too.
 *
 * The scrim blocks interaction with the app behind it. Hardware-back isn't
 * trapped on purpose: backing out just exits the app, and the freshly
 * downloaded bundle applies on the next cold start anyway — so the update can't
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
        <h2 class="ota-sheet__title">{{ titleKey() | translate }}</h2>
        <p class="ota-sheet__desc">{{ descKey() | translate }}</p>

        @if (status() === 'ready') {
          <button type="button" class="ota-sheet__primary" (click)="restart()">
            {{ 'ota_update_restart' | translate }}
          </button>
        } @else {
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
        }
      </section>
    }
  `,
  styleUrl: './ax-ota-indicator.component.scss',
})
export class AxOtaIndicatorComponent {
  private readonly ota = inject(OtaUpdateService);

  protected readonly status = this.ota.status;
  protected readonly percent = this.ota.percent;

  /** Blocking sheet is up whenever a bundle is downloading or ready to apply. */
  protected readonly visible = computed(
    () => this.status() === 'downloading' || this.status() === 'ready',
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

  private restartScheduled = false;

  constructor() {
    effect(() => {
      const s = this.status();
      // Auto-apply once the bundle is downloaded — a brief beat so the customer
      // sees it hit 100%, then reload() swaps in the new bundle.
      if (s === 'ready' && !this.restartScheduled) {
        this.restartScheduled = true;
        setTimeout(() => void this.ota.restartToApply(), 1200);
      } else if (s !== 'ready') {
        this.restartScheduled = false;
      }
    });
  }

  protected restart(): void {
    void this.ota.restartToApply();
  }
}
