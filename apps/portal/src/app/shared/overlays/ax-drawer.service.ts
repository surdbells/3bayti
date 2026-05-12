import { ComponentRef, InjectionToken, Injectable, Injector, Type, inject } from '@angular/core';
import { Overlay, OverlayConfig, OverlayRef } from '@angular/cdk/overlay';
import { ComponentPortal } from '@angular/cdk/portal';
import { Subject, Observable } from 'rxjs';

export type AxDrawerSize = 'sm' | 'md' | 'lg' | 'xl';
export type AxDrawerPosition = 'left' | 'right' | 'bottom';

export interface AxDrawerConfig<D = unknown> {
  /** Drawer width preset. Default 'md'. */
  size?: AxDrawerSize;
  /** Side of the screen to slide in from. Default 'right'. */
  position?: AxDrawerPosition;
  /** Arbitrary data passed to the opened component. */
  data?: D;
  /** Disable ESC close. */
  disableClose?: boolean;
  /** Disable backdrop click close. */
  disableBackdropClose?: boolean;
  /** Additional panel class(es). */
  panelClass?: string | string[];
  /** ARIA label. */
  ariaLabel?: string;
}

export const AX_DRAWER_DATA = new InjectionToken<unknown>('AX_DRAWER_DATA');

export class AxDrawerRef<TComponent = unknown, TResult = unknown> {
  private readonly _afterClosed$ = new Subject<TResult | undefined>();

  /** Set by AxDrawerService after `overlayRef.attach()` returns. */
  componentRef!: ComponentRef<TComponent>;

  constructor(private readonly overlayRef: OverlayRef) {}

  afterClosed(): Observable<TResult | undefined> {
    return this._afterClosed$.asObservable();
  }

  close(result?: TResult): void {
    if (!this.overlayRef.hasAttached()) return;
    this.overlayRef.detach();
    this.overlayRef.dispose();
    this._afterClosed$.next(result);
    this._afterClosed$.complete();
  }
}

/**
 * Opens a standalone component as a side drawer (off-canvas panel).
 * Replacement for Bootstrap's off-canvas. Useful for:
 * - "View order" details pane
 * - Inline forms (new customer, new product variant)
 * - Filters drawer on data tables
 *
 * Usage:
 *   const ref = this.drawer.open(ViewOrderComponent, {
 *     size: 'lg',
 *     position: 'right',
 *     data: { orderId: 42 }
 *   });
 */
@Injectable({ providedIn: 'root' })
export class AxDrawerService {
  private readonly overlay = inject(Overlay);
  private readonly parentInjector = inject(Injector);

  open<T, R = unknown, D = unknown>(
    component: Type<T>,
    config: AxDrawerConfig<D> = {},
  ): AxDrawerRef<T, R> {
    const size = config.size ?? 'md';
    const position = config.position ?? 'right';

    const extraClasses = ([] as string[])
      .concat(config.panelClass ?? [])
      .concat('ax-drawer', `ax-drawer-${size}`, `ax-drawer-${position}`);

    const positionStrategy = this.overlay.position().global();
    if (position === 'right') {
      positionStrategy.right('0').top('0');
    } else if (position === 'left') {
      positionStrategy.left('0').top('0');
    } else {
      // 'bottom' — full-width sheet anchored to bottom edge
      positionStrategy.bottom('0').left('0').right('0');
    }

    const overlayConfig = new OverlayConfig({
      hasBackdrop: true,
      backdropClass: 'ax-backdrop',
      panelClass: extraClasses,
      positionStrategy,
      scrollStrategy: this.overlay.scrollStrategies.block(),
      disposeOnNavigation: true,
      // Side drawers fill viewport height; bottom sheets size to content (capped via CSS).
      ...(position === 'bottom' ? {} : { height: '100vh' }),
    });

    const overlayRef = this.overlay.create(overlayConfig);

    // Construct drawer ref BEFORE attaching the portal so `inject(AxDrawerRef)`
    // inside the body component resolves to a real ref. `componentRef` is
    // assigned right after `attach()` returns.
    const drawerRef = new AxDrawerRef<T, R>(overlayRef);

    const injector = Injector.create({
      parent: this.parentInjector,
      providers: [
        { provide: AX_DRAWER_DATA, useValue: config.data ?? null },
        { provide: AxDrawerRef, useValue: drawerRef },
      ],
    });

    const portal = new ComponentPortal(component, null, injector);
    const componentRef = overlayRef.attach(portal);
    drawerRef.componentRef = componentRef;

    if (!config.disableBackdropClose) {
      overlayRef.backdropClick().subscribe(() => drawerRef.close());
    }
    if (!config.disableClose) {
      overlayRef.keydownEvents().subscribe(event => {
        if (event.key === 'Escape') {
          event.preventDefault();
          event.stopPropagation();
          drawerRef.close();
        }
      });
    }

    const paneEl = overlayRef.overlayElement;
    paneEl.setAttribute('role', 'dialog');
    paneEl.setAttribute('aria-modal', 'true');
    if (config.ariaLabel) {
      paneEl.setAttribute('aria-label', config.ariaLabel);
    }

    return drawerRef;
  }
}
