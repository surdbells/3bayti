import {
  Directive,
  ElementRef,
  HostListener,
  Input,
  OnDestroy,
  inject,
} from '@angular/core';
import {
  ConnectedPosition,
  Overlay,
  OverlayConfig,
  OverlayPositionBuilder,
  OverlayRef,
  ScrollStrategyOptions,
} from '@angular/cdk/overlay';

type AxTooltipPosition = 'top' | 'bottom' | 'left' | 'right';

const OPEN_DELAY_MS = 500;
const CLOSE_DELAY_MS = 100;

/**
 * Hover/focus tooltip.
 *
 *   <button [axTooltip]="'Refresh data'" axTooltipPosition="top">
 *
 * Positions auto-flip when near viewport edges. Dismissed on blur,
 * mouseleave, Escape, or scroll.
 */
@Directive({
  selector: '[axTooltip]',
  standalone: true,
})
export class AxTooltipDirective implements OnDestroy {
  @Input('axTooltip') text: string | null | undefined = '';
  @Input() axTooltipPosition: AxTooltipPosition = 'top';
  @Input() axTooltipDisabled = false;

  private overlayRef: OverlayRef | null = null;
  private tooltipEl: HTMLElement | null = null;
  private openTimer: ReturnType<typeof setTimeout> | null = null;
  private closeTimer: ReturnType<typeof setTimeout> | null = null;

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly overlay = inject(Overlay);
  private readonly positionBuilder = inject(OverlayPositionBuilder);
  private readonly scrollStrategies = inject(ScrollStrategyOptions);

  @HostListener('mouseenter') onMouseEnter() { this.scheduleOpen(); }
  @HostListener('mouseleave') onMouseLeave() { this.scheduleClose(); }
  @HostListener('focus')      onFocus()      { this.scheduleOpen(); }
  @HostListener('blur')       onBlur()       { this.hide(); }

  @HostListener('document:keydown.escape') onEsc() { this.hide(); }

  private scheduleOpen(): void {
    if (this.axTooltipDisabled || !this.text) return;
    if (this.closeTimer) { clearTimeout(this.closeTimer); this.closeTimer = null; }
    if (this.openTimer) return;
    this.openTimer = setTimeout(() => { this.openTimer = null; this.show(); }, OPEN_DELAY_MS);
  }

  private scheduleClose(): void {
    if (this.openTimer) { clearTimeout(this.openTimer); this.openTimer = null; }
    if (this.closeTimer) return;
    this.closeTimer = setTimeout(() => { this.closeTimer = null; this.hide(); }, CLOSE_DELAY_MS);
  }

  private show(): void {
    if (this.overlayRef || !this.text) return;

    const positions = this.positionsFor(this.axTooltipPosition);
    const positionStrategy = this.positionBuilder
      .flexibleConnectedTo(this.host)
      .withPositions(positions)
      .withPush(false)
      .withFlexibleDimensions(false);

    const config = new OverlayConfig({
      positionStrategy,
      scrollStrategy: this.scrollStrategies.close(),
    });

    this.overlayRef = this.overlay.create(config);
    this.tooltipEl = document.createElement('div');
    this.tooltipEl.className = 'ax-tooltip';
    this.tooltipEl.textContent = this.text;
    this.tooltipEl.setAttribute('role', 'tooltip');
    this.overlayRef.overlayElement.appendChild(this.tooltipEl);

    // Small delay to allow CSS transition
    requestAnimationFrame(() => {
      this.tooltipEl?.classList.add('ax-tooltip-visible');
    });
  }

  private hide(): void {
    if (this.openTimer) { clearTimeout(this.openTimer); this.openTimer = null; }
    if (this.closeTimer) { clearTimeout(this.closeTimer); this.closeTimer = null; }
    if (!this.overlayRef) return;
    this.tooltipEl?.classList.remove('ax-tooltip-visible');
    // Detach after fade-out
    const ref = this.overlayRef;
    this.overlayRef = null;
    setTimeout(() => {
      ref.detach();
      ref.dispose();
    }, 150);
    this.tooltipEl = null;
  }

  private positionsFor(pos: AxTooltipPosition): ConnectedPosition[] {
    const offset = 8;
    switch (pos) {
      case 'top':
        return [
          { originX: 'center', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -offset },
          { originX: 'center', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: offset },
        ];
      case 'bottom':
        return [
          { originX: 'center', originY: 'bottom', overlayX: 'center', overlayY: 'top', offsetY: offset },
          { originX: 'center', originY: 'top', overlayX: 'center', overlayY: 'bottom', offsetY: -offset },
        ];
      case 'left':
        return [
          { originX: 'start', originY: 'center', overlayX: 'end', overlayY: 'center', offsetX: -offset },
          { originX: 'end', originY: 'center', overlayX: 'start', overlayY: 'center', offsetX: offset },
        ];
      case 'right':
        return [
          { originX: 'end', originY: 'center', overlayX: 'start', overlayY: 'center', offsetX: offset },
          { originX: 'start', originY: 'center', overlayX: 'end', overlayY: 'center', offsetX: -offset },
        ];
    }
  }

  ngOnDestroy(): void {
    if (this.openTimer) clearTimeout(this.openTimer);
    if (this.closeTimer) clearTimeout(this.closeTimer);
    this.overlayRef?.dispose();
  }
}
