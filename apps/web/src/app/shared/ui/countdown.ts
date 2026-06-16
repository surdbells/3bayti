import {
  Component,
  ChangeDetectionStrategy,
  Input,
  OnInit,
  OnDestroy,
  signal,
  computed,
} from '@angular/core';

/**
 * Countdown — a compact days/hours/minutes/seconds timer to a deadline.
 *
 * Server-clock aware: when [serverNow] (the API's clock at response time)
 * is supplied, the component measures the device's offset from it once and
 * counts down against the server clock — so a skewed device clock can't
 * misreport the time remaining. Ticks once a second; clears on destroy.
 *
 * Accessibility: the ticking digits are aria-hidden (announcing every
 * second would be noise); the container is role="timer" with a calmer
 * aria-label ("Ends in 2 days, 4 hours, 13 minutes"). No flashing, so it's
 * already reduced-motion safe.
 */
@Component({
  selector: 'ui-countdown',
  standalone: true,
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="cd" role="timer" [attr.aria-label]="ariaLabel()">
      @if (!expired()) {
        <span class="cd__unit">
          <span class="cd__num" aria-hidden="true">{{ parts().d }}</span>
          <span class="cd__lbl" aria-hidden="true">days</span>
        </span>
        <span class="cd__sep" aria-hidden="true">:</span>
        <span class="cd__unit">
          <span class="cd__num" aria-hidden="true">{{ pad(parts().h) }}</span>
          <span class="cd__lbl" aria-hidden="true">hrs</span>
        </span>
        <span class="cd__sep" aria-hidden="true">:</span>
        <span class="cd__unit">
          <span class="cd__num" aria-hidden="true">{{ pad(parts().m) }}</span>
          <span class="cd__lbl" aria-hidden="true">min</span>
        </span>
        <span class="cd__sep" aria-hidden="true">:</span>
        <span class="cd__unit">
          <span class="cd__num" aria-hidden="true">{{ pad(parts().s) }}</span>
          <span class="cd__lbl" aria-hidden="true">sec</span>
        </span>
      } @else {
        <span class="cd__ended">Ended</span>
      }
    </div>
  `,
  styles: [`
    .cd {
      display: inline-flex;
      align-items: flex-start;
      gap: var(--space-2xs);
    }
    .cd__unit {
      display: inline-flex;
      flex-direction: column;
      align-items: center;
      min-width: 2.4ch;
    }
    .cd__num {
      font-variant-numeric: tabular-nums;
      font-weight: 700;
      font-size: 1.15rem;
      line-height: 1.1;
      color: var(--color-brand-700);
    }
    .cd__lbl {
      font-size: 0.6rem;
      letter-spacing: 0.08em;
      text-transform: uppercase;
      color: var(--color-text-tertiary);
    }
    .cd__sep {
      font-weight: 700;
      font-size: 1.05rem;
      line-height: 1.2;
      color: var(--color-text-tertiary);
      transform: translateY(1px);
    }
    .cd__ended {
      font-weight: 600;
      color: var(--color-text-secondary);
    }
  `],
})
export class CountdownComponent implements OnInit, OnDestroy {
  /** ISO 8601 deadline. */
  @Input({ required: true }) endsAt!: string;
  /** ISO 8601 server clock at response time (optional). */
  @Input() serverNow?: string;

  private offsetMs = 0;
  private timer?: ReturnType<typeof setInterval>;
  private readonly nowMs = signal(Date.now());

  readonly remainingMs = computed(() => {
    const end = new Date(this.endsAt).getTime();
    if (isNaN(end)) return 0;
    const serverAdjustedNow = this.nowMs() - this.offsetMs;
    return Math.max(0, end - serverAdjustedNow);
  });

  readonly expired = computed(() => this.remainingMs() <= 0);

  readonly parts = computed(() => {
    let ms = this.remainingMs();
    const d = Math.floor(ms / 86_400_000); ms -= d * 86_400_000;
    const h = Math.floor(ms / 3_600_000);  ms -= h * 3_600_000;
    const m = Math.floor(ms / 60_000);     ms -= m * 60_000;
    const s = Math.floor(ms / 1_000);
    return { d, h, m, s };
  });

  readonly ariaLabel = computed(() => {
    if (this.expired()) return 'Offer ended';
    const p = this.parts();
    const bits: string[] = [];
    if (p.d) bits.push(`${p.d} day${p.d === 1 ? '' : 's'}`);
    bits.push(`${p.h} hour${p.h === 1 ? '' : 's'}`);
    bits.push(`${p.m} minute${p.m === 1 ? '' : 's'}`);
    return `Ends in ${bits.join(', ')}`;
  });

  ngOnInit(): void {
    if (this.serverNow) {
      const serverMs = new Date(this.serverNow).getTime();
      if (!isNaN(serverMs)) {
        this.offsetMs = Date.now() - serverMs;
      }
    }
    this.timer = setInterval(() => this.nowMs.set(Date.now()), 1000);
  }

  ngOnDestroy(): void {
    if (this.timer) {
      clearInterval(this.timer);
    }
  }

  pad(n: number): string {
    return String(n).padStart(2, '0');
  }
}
