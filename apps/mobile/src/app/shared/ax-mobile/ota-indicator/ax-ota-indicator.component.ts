import { ChangeDetectionStrategy, Component, computed, effect, inject, signal } from '@angular/core';
import { OtaUpdateService } from '../../../core/services/ota-update.service';

/**
 * AxOtaIndicatorComponent — a deliberately SILENT, non-intrusive OTA progress
 * cue: a thin (3px) bar pinned to the very top of the screen that fills while a
 * bundle downloads and quietly fades out once it's staged. No modal, no text,
 * no blocking — `pointer-events: none` so it can never intercept a tap.
 *
 * Purely reactive off OtaUpdateService; it shows progress for both the
 * automatic resume-time download and any on-demand dashboard check.
 */
@Component({
  selector: 'ax-ota-indicator',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    @if (show()) {
      <div
        class="ax-ota-bar"
        [class.ax-ota-bar--hiding]="hiding()"
        role="status"
        aria-live="polite"
        aria-label="Updating app">
        <div class="ax-ota-bar__fill" [style.width.%]="width()"></div>
      </div>
    }
  `,
  styleUrl: './ax-ota-indicator.component.scss',
})
export class AxOtaIndicatorComponent {
  private readonly ota = inject(OtaUpdateService);

  private readonly status = this.ota.status;
  private readonly _show = signal(false);
  private readonly _hiding = signal(false);
  private hideTimer: ReturnType<typeof setTimeout> | null = null;

  protected readonly show = this._show.asReadonly();
  protected readonly hiding = this._hiding.asReadonly();
  /** 'ready' pins the bar to 100% for its brief settle before fading. */
  protected readonly width = computed(() => (this.status() === 'ready' ? 100 : this.ota.percent()));

  constructor() {
    effect(() => {
      const s = this.status();
      if (this.hideTimer) {
        clearTimeout(this.hideTimer);
        this.hideTimer = null;
      }
      if (s === 'downloading') {
        this._hiding.set(false);
        this._show.set(true);
      } else if (s === 'ready') {
        this._hiding.set(false);
        this._show.set(true);
        // Hold the full bar briefly, then fade it away.
        this.hideTimer = setTimeout(() => this.beginHide(), 900);
      } else if (this._show()) {
        // idle / checking / failed — retire any visible bar.
        this.beginHide();
      }
    });
  }

  private beginHide(): void {
    this._hiding.set(true); // CSS fades opacity -> 0
    if (this.hideTimer) {
      clearTimeout(this.hideTimer);
    }
    this.hideTimer = setTimeout(() => {
      this._show.set(false);
      this._hiding.set(false);
    }, 320);
  }
}
