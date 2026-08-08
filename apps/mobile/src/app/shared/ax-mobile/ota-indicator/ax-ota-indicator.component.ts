import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { TranslatePipe } from '../../../translate.pipe';
import { OtaStatus, OtaUpdateService } from '../../../core/services/ota-update.service';

/**
 * AxOtaIndicatorComponent — a VISIBLE (but dismissible) OTA update sheet.
 *
 * The customer should see updates happen, so a bottom sheet slides up while a
 * bundle downloads: title + description + a live progress bar with percent and
 * a rough ETA. When the download finishes it flips to a "ready" state offering
 * "Restart now" (applies immediately) or "Later" (applies on the next cold
 * start). It's non-trapping — the scrim / "Hide" dismisses it and the download
 * keeps going in the background.
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
      <div class="ota-sheet-scrim" (click)="dismiss()"></div>
      <section class="ota-sheet" role="dialog" aria-live="polite" [attr.aria-label]="titleKey() | translate">
        <span class="ota-sheet__grip" aria-hidden="true"></span>
        <h2 class="ota-sheet__title">{{ titleKey() | translate }}</h2>
        <p class="ota-sheet__desc">{{ descKey() | translate }}</p>

        @if (status() === 'ready') {
          <div class="ota-sheet__actions">
            <button type="button" class="ota-sheet__primary" (click)="restart()">
              {{ 'ota_update_restart' | translate }}
            </button>
            <button type="button" class="ota-sheet__ghost" (click)="dismiss()">
              {{ 'ota_update_later' | translate }}
            </button>
          </div>
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
          <button type="button" class="ota-sheet__ghost ota-sheet__ghost--center" (click)="dismiss()">
            {{ 'ota_update_hide' | translate }}
          </button>
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

  private readonly _dismissed = signal(false);
  private lastStatus: OtaStatus = 'idle';

  protected readonly visible = computed(
    () => !this._dismissed() && (this.status() === 'downloading' || this.status() === 'ready'),
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

  constructor() {
    effect(() => {
      const s = this.status();
      // A fresh download re-reveals the sheet even if a prior one was hidden.
      if (s === 'downloading' && this.lastStatus !== 'downloading') {
        this._dismissed.set(false);
      }
      this.lastStatus = s;
    });
  }

  protected dismiss(): void {
    this._dismissed.set(true);
  }

  protected restart(): void {
    this._dismissed.set(true);
    void this.ota.restartToApply();
  }
}
