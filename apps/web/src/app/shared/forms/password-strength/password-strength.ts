import {
  Component,
  ChangeDetectionStrategy,
  Input,
  signal,
  computed,
  effect,
  inject,
  PLATFORM_ID,
  OnChanges,
  SimpleChanges,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * Password strength meter.
 *
 * Drives a five-bar visual + textual rating from zxcvbn's score (0-4).
 * The library is loaded lazily on first non-empty input so the
 * auth-less catalog pages don't pay the bundle cost.
 *
 * Why lazy load
 * -------------
 * @zxcvbn-ts/core + the language dictionaries together weigh ~280 KB
 * pre-gzip. Static-imported at the root, that's added to every initial
 * bundle. Lazy-loaded with `import('@zxcvbn-ts/core')`, the chunk only
 * downloads when the user starts typing a password on register or
 * reset-password — typically a fraction of all visitors.
 *
 * SSR
 * ---
 * On the server we skip the import entirely; the meter renders as
 * empty bars during prerender / SSR. The score appears on hydration
 * once the chunk loads. This is fine UX: a static prerender of
 * /register doesn't show "your password is strong" anyway because
 * the user hasn't typed yet.
 *
 * Score → label mapping
 * ---------------------
 *   0  Very weak     red
 *   1  Weak          orange
 *   2  Fair          yellow
 *   3  Strong        light green
 *   4  Very strong   green
 *
 * The i18n keys live under `auth.passwordStrength.*` in
 * public/i18n/{en,ar}.json.
 *
 * Why a small inline implementation rather than wrapping a 3rd-party
 * meter component
 * -----------------------------------------------------------------
 * The meter is ~50 lines of visual logic plus dictionary loading.
 * Adding a 3rd-party meter component would introduce design-system
 * conflicts and another peer dep version to juggle. The bundle saving
 * vs. complexity is not worth it.
 */

interface ZxcvbnAnalysis {
  /** 0-4. */
  score: 0 | 1 | 2 | 3 | 4;
  /** Optional warning message (user-facing). */
  warning: string;
}

/**
 * Cache the loaded library + options so subsequent component instances
 * share one chunk download.
 */
let zxcvbnInstance:
  | ((password: string) => ZxcvbnAnalysis)
  | null = null;
let zxcvbnLoadPromise: Promise<((password: string) => ZxcvbnAnalysis) | null> | null = null;

/**
 * Lazy-load zxcvbn-ts and return a tuned scorer. Re-entrant: concurrent
 * callers share the same in-flight promise.
 */
export async function loadPasswordScorer(): Promise<((password: string) => ZxcvbnAnalysis) | null> {
  if (zxcvbnInstance !== null) return zxcvbnInstance;
  if (zxcvbnLoadPromise !== null) return zxcvbnLoadPromise;

  zxcvbnLoadPromise = (async () => {
    try {
      /* Dynamic imports — these create separate JS chunks at build time. */
      const [core, common, en] = await Promise.all([
        import('@zxcvbn-ts/core'),
        import('@zxcvbn-ts/language-common'),
        import('@zxcvbn-ts/language-en'),
      ]);

      core.zxcvbnOptions.setOptions({
        translations: en.translations,
        graphs: common.adjacencyGraphs,
        dictionary: {
          ...common.dictionary,
          ...en.dictionary,
        },
      });

      const scorer = (password: string): ZxcvbnAnalysis => {
        const result = core.zxcvbn(password);
        return {
          score: result.score as 0 | 1 | 2 | 3 | 4,
          warning: result.feedback.warning ?? '',
        };
      };
      zxcvbnInstance = scorer;
      return scorer;
    } catch (err) {
      /* If the chunk fails to load (offline, CDN issue), the meter
         simply doesn't appear — graceful degradation. */
      if (typeof console !== 'undefined') {
        console.warn('[password-strength] zxcvbn-ts load failed', err);
      }
      return null;
    } finally {
      zxcvbnLoadPromise = null;
    }
  })();
  return zxcvbnLoadPromise;
}

/**
 * Test-only: reset the cached instance so each test sees a fresh load
 * cycle. Not exported from the barrel — only the spec imports this.
 */
export function _resetPasswordScorerForTest(): void {
  zxcvbnInstance = null;
  zxcvbnLoadPromise = null;
}

const STRENGTH_LABEL_KEYS: Record<number, string> = {
  0: 'auth.passwordStrength.veryWeak',
  1: 'auth.passwordStrength.weak',
  2: 'auth.passwordStrength.fair',
  3: 'auth.passwordStrength.strong',
  4: 'auth.passwordStrength.veryStrong',
};

@Component({
  selector: 'ui-password-strength',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="strength" *ngIf="password !== ''" [attr.data-score]="score()">
      <div class="strength__bars" aria-hidden="true">
        <span class="strength__bar" [class.strength__bar--filled]="score() >= 0"></span>
        <span class="strength__bar" [class.strength__bar--filled]="score() >= 1"></span>
        <span class="strength__bar" [class.strength__bar--filled]="score() >= 2"></span>
        <span class="strength__bar" [class.strength__bar--filled]="score() >= 3"></span>
        <span class="strength__bar" [class.strength__bar--filled]="score() >= 4"></span>
      </div>
      <p class="strength__label" role="status" aria-live="polite">
        <span class="strength__label-prefix">{{ 'auth.passwordStrength.label' | translate }}:</span>
        {{ labelKey() | translate }}
      </p>
    </div>
  `,
  styles: [
    `
      .strength {
        display: flex;
        flex-direction: column;
        gap: 4px;
        margin-top: 4px;
      }

      .strength__bars {
        display: flex;
        gap: 4px;
      }

      .strength__bar {
        flex: 1;
        height: 4px;
        background: var(--color-bg-muted, #e8e2d6);
        border-radius: 2px;
        transition: background-color 0.2s ease;
      }

      .strength[data-score='0'] .strength__bar--filled {
        background: #c0392b;
      }
      .strength[data-score='1'] .strength__bar--filled {
        background: #e67e22;
      }
      .strength[data-score='2'] .strength__bar--filled {
        background: #f1c40f;
      }
      .strength[data-score='3'] .strength__bar--filled {
        background: #7cb342;
      }
      .strength[data-score='4'] .strength__bar--filled {
        background: #2e7d32;
      }

      .strength__label {
        font-size: 12px;
        margin: 0;
        color: var(--color-text-muted, #6b6056);
      }

      .strength__label-prefix {
        font-weight: 500;
      }
    `,
  ],
})
export class PasswordStrengthComponent implements OnChanges {
  /** The current password value. Falsy → meter hidden. */
  @Input() password = '';

  /** -1 means "not yet scored"; the meter hides via the *ngIf check
   *  on password rather than score. Once the lazy chunk loads we
   *  set a real 0-4 score. */
  private readonly _score = signal<number>(-1);
  protected readonly score = computed(() => (this._score() < 0 ? 0 : this._score()));
  protected readonly labelKey = computed(() => STRENGTH_LABEL_KEYS[this._score() < 0 ? 0 : this._score()]);

  private readonly platformId = inject(PLATFORM_ID);

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['password'] !== undefined) {
      void this.scorePassword(changes['password'].currentValue as string);
    }
  }

  private async scorePassword(password: string): Promise<void> {
    if (password === '') {
      this._score.set(-1);
      return;
    }
    /* Skip scoring on the server — zxcvbn would load and crunch even
       though the result is never displayed. Wait until the browser
       hydrates. */
    if (!isPlatformBrowser(this.platformId)) {
      return;
    }
    const scorer = await loadPasswordScorer();
    if (scorer === null) {
      /* Library failed to load — give a conservative middle score so
         the meter shows something. */
      this._score.set(2);
      return;
    }
    /* Check the password is still the same one we started scoring;
       the user may have typed more characters while the chunk loaded. */
    if (this.password !== password) {
      /* Re-score with the latest value. The recursive call is bounded
         because the chunk is already in memory now. */
      void this.scorePassword(this.password);
      return;
    }
    const analysis = scorer(password);
    this._score.set(analysis.score);
  }
}
