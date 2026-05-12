import {
  Directive,
  ElementRef,
  HostListener,
  Input,
  OnDestroy,
  TemplateRef,
  ViewContainerRef,
  inject,
} from '@angular/core';
import {
  ConnectedPosition,
  Overlay,
  OverlayConfig,
  OverlayRef,
  ScrollStrategyOptions,
} from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';

type AxPopoverPosition = 'top' | 'bottom' | 'left' | 'right';

/**
 * Click-triggered popover with arbitrary rich content from an ng-template.
 *
 *   <button [axPopover]="helpTpl" axPopoverPosition="bottom">Help</button>
 *   <ng-template #helpTpl>
 *     <div class="ax-popover-header">Quick tip</div>
 *     <div class="ax-popover-body">You can drag columns to reorder them.</div>
 *   </ng-template>
 *
 * Dismisses on outside click, Escape, or scroll.
 */
@Directive({
  selector: '[axPopover]',
  standalone: true,
})
export class AxPopoverDirective implements OnDestroy {
  @Input('axPopover') template: TemplateRef<unknown> | null = null;
  @Input() axPopoverPosition: AxPopoverPosition = 'bottom';
  @Input() axPopoverDisabled = false;

  private overlayRef: OverlayRef | null = null;

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly viewContainerRef = inject(ViewContainerRef);
  private readonly overlay = inject(Overlay);
  private readonly scrollStrategies = inject(ScrollStrategyOptions);

  @HostListener('click', ['$event'])
  onClick(event: MouseEvent): void {
    if (this.axPopoverDisabled || !this.template) return;
    event.stopPropagation();
    this.overlayRef ? this.close() : this.open();
  }

  private open(): void {
    if (this.overlayRef || !this.template) return;

    const positions = this.positionsFor(this.axPopoverPosition);
    const positionStrategy = this.overlay
      .position()
      .flexibleConnectedTo(this.host)
      .withPositions(positions);

    const config = new OverlayConfig({
      positionStrategy,
      scrollStrategy: this.scrollStrategies.reposition(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
      panelClass: ['ax-popover'],
    });

    this.overlayRef = this.overlay.create(config);
    this.overlayRef.overlayElement.setAttribute('role', 'dialog');

    const portal = new TemplatePortal(this.template, this.viewContainerRef);
    this.overlayRef.attach(portal);

    // Fade in after attach
    const el = this.overlayRef.overlayElement;
    requestAnimationFrame(() => {
      el.classList.add('ax-popover-visible');
    });

    this.overlayRef.backdropClick().subscribe(() => this.close());
    this.overlayRef.keydownEvents().subscribe(event => {
      if (event.key === 'Escape') { event.preventDefault(); this.close(); }
    });
  }

  private close(): void {
    if (!this.overlayRef) return;
    this.overlayRef.overlayElement.classList.remove('ax-popover-visible');
    const ref = this.overlayRef;
    this.overlayRef = null;
    setTimeout(() => {
      ref.detach();
      ref.dispose();
    }, 150);
  }

  private positionsFor(pos: AxPopoverPosition): ConnectedPosition[] {
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
    this.overlayRef?.dispose();
  }
}
