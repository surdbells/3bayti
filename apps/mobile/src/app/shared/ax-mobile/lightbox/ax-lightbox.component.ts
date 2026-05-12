import {
  Component,
  EventEmitter,
  HostListener,
  Input,
  Output,
} from '@angular/core';
import { CommonModule } from '@angular/common';

import { AxIconComponent } from '../icon';

/**
 * <ax-lightbox> — fullscreen image viewer primitive.
 *
 * Design intent: minimal, low-distraction image lightbox for
 * tapped-from-context images (chat attachments, gallery thumbs,
 * etc). Renders as a fixed-position black overlay, image
 * centered + contain-fit, close button top-right.
 *
 * v1 features:
 *   - Fullscreen black background (95% opacity for context)
 *   - Centered, contain-fit image
 *   - Close button (top-right, safe-area-aware)
 *   - Tap-image-background to dismiss
 *   - Escape key to dismiss
 *   - alt text for accessibility
 *
 * v1 NON-features (deferred to follow-up):
 *   - Pinch-zoom + pan
 *   - Swipe-down-to-dismiss gesture
 *   - Multi-image carousel
 *   - Caption / metadata display
 *   - Download / share buttons
 *
 * Usage:
 *   <ax-lightbox
 *     [isOpen]="isImageViewerOpen"
 *     [src]="viewingImage?.file_path"
 *     [alt]="viewingImage?.file_name"
 *     (closed)="closeImageViewer()">
 *   </ax-lightbox>
 *
 * The component is self-contained: it manages its own DOM
 * insertion via Angular structural rendering (the host element
 * is hidden when isOpen=false). When integrating with state
 * that has a separate "image data" + "open" flag (like the
 * chat page's viewingImage + isImageViewerOpen pair), bind
 * src independently — the component just renders whatever src
 * resolves to.
 */
@Component({
  selector: 'ax-lightbox',
  standalone: true,
  imports: [CommonModule, AxIconComponent],
  template: `
    @if (isOpen) {
      <div class="ax-lightbox" (click)="onBackdropClick($event)" role="dialog" aria-modal="true">
        <button
          type="button"
          class="ax-lightbox__close"
          (click)="onCloseClick($event)"
          aria-label="Close image viewer">
          <ax-icon name="x" [size]="24"></ax-icon>
        </button>
        <div class="ax-lightbox__container">
          @if (src) {
            <img
              class="ax-lightbox__image"
              [src]="src"
              [alt]="alt || ''"
              (click)="$event.stopPropagation()" />
          }
        </div>
      </div>
    }
  `,
  styles: [`
    :host {
      display: contents;
    }

    .ax-lightbox {
      position: fixed;
      inset: 0;
      z-index: 9999;
      background: rgba(0, 0, 0, 0.95);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 16px;
      cursor: pointer;
      animation: ax-lightbox-fade-in 0.18s ease-out;
    }

    @keyframes ax-lightbox-fade-in {
      from { opacity: 0; }
      to { opacity: 1; }
    }

    .ax-lightbox__close {
      position: absolute;
      top: calc(env(safe-area-inset-top, 0px) + 12px);
      right: 16px;
      z-index: 1;
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: rgba(255, 255, 255, 0.12);
      color: var(--ax-palette-neutral-0, #ffffff);
      border: none;
      display: flex;
      align-items: center;
      justify-content: center;
      cursor: pointer;
      -webkit-tap-highlight-color: transparent;
      backdrop-filter: blur(6px);
      -webkit-backdrop-filter: blur(6px);
      transition: background 0.18s ease, transform 0.12s ease;

      &:active {
        transform: scale(0.94);
        background: rgba(255, 255, 255, 0.22);
      }
    }

    .ax-lightbox__container {
      width: 100%;
      height: 100%;
      display: flex;
      align-items: center;
      justify-content: center;
    }

    .ax-lightbox__image {
      max-width: 100%;
      max-height: 100%;
      object-fit: contain;
      cursor: default;
    }

    @media (prefers-reduced-motion: reduce) {
      .ax-lightbox {
        animation: none;
      }
    }
  `],
})
export class AxLightboxComponent {
  /** Whether the lightbox is currently shown. */
  @Input() isOpen = false;

  /** Image source URL. */
  @Input() src: string | null | undefined = null;

  /** Image alt text for accessibility. */
  @Input() alt: string | null | undefined = null;

  /** Emitted when the user dismisses the lightbox (close button,
   *  backdrop tap, or Escape key). Named 'closed' (not 'close') to
   *  avoid the @angular-eslint/no-output-native rule — 'close' is
   *  also a native DOM event name. */
  @Output() closed = new EventEmitter<void>();

  onBackdropClick(_event: MouseEvent): void {
    // Backdrop click — image clicks are stopped via stopPropagation in template
    this.closed.emit();
  }

  onCloseClick(event: MouseEvent): void {
    event.stopPropagation();
    this.closed.emit();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    if (this.isOpen) {
      this.closed.emit();
    }
  }
}
