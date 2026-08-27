import {
  Component,
  ChangeDetectionStrategy,
  Input,
  signal,
  inject,
  PLATFORM_ID,
  OnDestroy,
} from '@angular/core';
import { isPlatformBrowser } from '@angular/common';
import { TranslatePipe } from '@ngx-translate/core';

/**
 * ShareButtons, social share row for a single canonical URL.
 *
 * Six channels (decision #5):
 *   WhatsApp · copy link · native share · Facebook · X · Telegram
 *
 * The four network buttons are real anchors (good for a11y + work with
 * no JS) opening in a new tab; copy + native are JS actions. Native
 * share is feature-detected and only rendered in a browser that exposes
 * the Web Share API (mobile / some desktops). Copy uses the async
 * clipboard API and shows a transient "Copied!" confirmation.
 *
 * Pure + reusable: pass the absolute `url` to share and an optional
 * `title` (usually the product name) used as the share text. Monochrome
 * brand glyphs keep it consistent with the gilded boutique aesthetic
 * rather than rainbow network colours.
 *
 * Usage:
 *   <ui-share-buttons [url]="shareUrl()" [title]="product.name" />
 */
@Component({
  selector: 'ui-share-buttons',
  standalone: true,
  imports: [TranslatePipe],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="share">
      <span class="share__label">{{ 'ui.share.label' | translate }}</span>

      <a
        class="share__btn"
        [href]="whatsappUrl()"
        target="_blank"
        rel="noopener noreferrer"
        [attr.aria-label]="'ui.share.whatsapp' | translate"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.71.306 1.263.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 0 1-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 0 1-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 0 1 2.893 6.994c-.003 5.45-4.437 9.885-9.885 9.885M20.52 3.449C18.24 1.245 15.24 0 12.045 0 5.463 0 .104 5.358.101 11.892c0 2.096.549 4.142 1.595 5.945L0 24l6.305-1.654a11.882 11.882 0 0 0 5.683 1.448h.005c6.582 0 11.94-5.358 11.944-11.893a11.821 11.821 0 0 0-3.487-8.45"
          />
        </svg>
      </a>

      <a
        class="share__btn"
        [href]="facebookUrl()"
        target="_blank"
        rel="noopener noreferrer"
        [attr.aria-label]="'ui.share.facebook' | translate"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M9.101 23.691v-7.98H6.627v-3.667h2.474v-1.58c0-4.085 1.848-5.978 5.858-5.978.401 0 .955.042 1.468.103a8.68 8.68 0 0 1 1.141.195v3.325a8.623 8.623 0 0 0-.653-.036 26.805 26.805 0 0 0-.733-.009c-.707 0-1.259.096-1.675.309a1.686 1.686 0 0 0-.679.622c-.258.42-.374.995-.374 1.752v1.297h3.919l-.386 2.103-.287 1.564h-3.246v8.245C19.396 23.238 24 18.179 24 12.044c0-6.627-5.373-12-12-12s-12 5.373-12 12c0 5.628 3.874 10.35 9.101 11.647Z"
          />
        </svg>
      </a>

      <a
        class="share__btn"
        [href]="xUrl()"
        target="_blank"
        rel="noopener noreferrer"
        [attr.aria-label]="'ui.share.x' | translate"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"
          />
        </svg>
      </a>

      <a
        class="share__btn"
        [href]="telegramUrl()"
        target="_blank"
        rel="noopener noreferrer"
        [attr.aria-label]="'ui.share.telegram' | translate"
      >
        <svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
          <path
            d="M11.944 0A12 12 0 0 0 0 12a12 12 0 0 0 12 12 12 12 0 0 0 12-12A12 12 0 0 0 12 0a12 12 0 0 0-.056 0zm4.962 7.224c.1-.002.321.023.465.14a.506.506 0 0 1 .171.325c.016.093.036.306.02.472-.18 1.898-.962 6.502-1.36 8.627-.168.9-.499 1.201-.82 1.23-.696.065-1.225-.46-1.9-.902-1.056-.693-1.653-1.124-2.678-1.8-1.185-.78-.417-1.21.258-1.91.177-.184 3.247-2.977 3.307-3.23.007-.032.014-.15-.056-.212s-.174-.041-.249-.024c-.106.024-1.793 1.14-5.061 3.345-.48.33-.913.49-1.302.48-.428-.008-1.252-.241-1.865-.44-.752-.245-1.349-.374-1.297-.789.027-.216.325-.437.893-.663 3.498-1.524 5.83-2.529 6.998-3.014 3.332-1.386 4.025-1.627 4.476-1.635z"
          />
        </svg>
      </a>

      <button
        type="button"
        class="share__btn"
        [class.is-copied]="copied()"
        (click)="copyLink()"
        [attr.aria-label]="copied() ? ('ui.share.linkCopied' | translate) : ('ui.share.copyLink' | translate)"
      >
        @if (copied()) {
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M20 6L9 17l-5-5" />
          </svg>
        } @else {
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71" />
            <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71" />
          </svg>
        }
      </button>

      @if (canNativeShare()) {
        <button
          type="button"
          class="share__btn"
          (click)="nativeShare()"
          [attr.aria-label]="'ui.share.nativeShare' | translate"
        >
          <svg
            viewBox="0 0 24 24"
            fill="none"
            stroke="currentColor"
            stroke-width="2"
            stroke-linecap="round"
            stroke-linejoin="round"
            aria-hidden="true"
          >
            <circle cx="18" cy="5" r="3" />
            <circle cx="6" cy="12" r="3" />
            <circle cx="18" cy="19" r="3" />
            <line x1="8.59" y1="13.51" x2="15.42" y2="17.49" />
            <line x1="15.41" y1="6.51" x2="8.59" y2="10.49" />
          </svg>
        </button>
      }

      <span class="share__status" role="status" aria-live="polite">{{
        copied() ? ('ui.share.copied' | translate) : ''
      }}</span>
    </div>
  `,
  styles: [
    `
      :host {
        display: block;
      }
      .share {
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
      }
      .share__label {
        font-size: 13px;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--color-text-tertiary);
        margin-inline-end: 2px;
      }
      .share__btn {
        width: 40px;
        height: 40px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 50%;
        border: 1px solid var(--color-border-subtle);
        background: var(--color-bg-surface);
        color: var(--color-brand-700);
        cursor: pointer;
        text-decoration: none;
        padding: 0;
        appearance: none;
        transition:
          background 0.18s ease,
          border-color 0.18s ease,
          transform 0.18s ease;
      }
      .share__btn:hover {
        background: var(--color-brand-50);
        border-color: var(--color-brand-300);
        transform: translateY(-1px);
      }
      .share__btn:focus-visible {
        outline: 2px solid var(--color-brand-500);
        outline-offset: 2px;
      }
      .share__btn svg {
        width: 18px;
        height: 18px;
        display: block;
      }
      .share__btn.is-copied {
        color: #16a34a;
        border-color: #16a34a;
        background: rgba(22, 163, 74, 0.08);
      }
      .share__status {
        font-size: 13px;
        font-weight: 500;
        color: #16a34a;
        min-width: 0;
      }
    `,
  ],
})
export class ShareButtonsComponent implements OnDestroy {
  /** Absolute URL to share (canonical PDP URL). */
  @Input({ required: true }) url!: string;
  /** Share text / title, usually the product name. */
  @Input() title = '';

  private readonly platformId = inject(PLATFORM_ID);
  /** Transient "copied" confirmation state. */
  readonly copied = signal(false);
  private resetTimer: ReturnType<typeof setTimeout> | null = null;

  /** True only in a browser that supports the Web Share API. */
  canNativeShare(): boolean {
    return (
      isPlatformBrowser(this.platformId) &&
      typeof navigator !== 'undefined' &&
      typeof navigator.share === 'function'
    );
  }

  whatsappUrl(): string {
    const text = this.title ? `${this.title} ${this.url}` : this.url;
    return `https://wa.me/?text=${encodeURIComponent(text)}`;
  }

  facebookUrl(): string {
    return `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(this.url)}`;
  }

  xUrl(): string {
    return `https://twitter.com/intent/tweet?url=${encodeURIComponent(
      this.url,
    )}&text=${encodeURIComponent(this.title)}`;
  }

  telegramUrl(): string {
    return `https://t.me/share/url?url=${encodeURIComponent(
      this.url,
    )}&text=${encodeURIComponent(this.title)}`;
  }

  async copyLink(): Promise<void> {
    if (!isPlatformBrowser(this.platformId)) return;
    try {
      await navigator.clipboard.writeText(this.url);
      this.copied.set(true);
      if (this.resetTimer) clearTimeout(this.resetTimer);
      this.resetTimer = setTimeout(() => this.copied.set(false), 2000);
    } catch {
      /* Clipboard blocked (insecure context / denied), no-op; the
         network share buttons still work. */
    }
  }

  async nativeShare(): Promise<void> {
    if (!this.canNativeShare()) return;
    try {
      await navigator.share({
        title: this.title || undefined,
        url: this.url,
      });
    } catch {
      /* User cancelled (AbortError) or share failed, ignore. */
    }
  }

  ngOnDestroy(): void {
    if (this.resetTimer) clearTimeout(this.resetTimer);
  }
}
