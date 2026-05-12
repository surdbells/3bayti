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

type AxDropdownAlign = 'start' | 'end';

/**
 * Menu-style dropdown. Different from AxPopover:
 *   - Uses the .ax-dropdown-menu panel styling (Phase 1) — menu aesthetic.
 *   - Auto-closes when a [axDropdownItem] inside the menu is clicked.
 *   - Closes on outside click, Escape.
 *
 *   <button [axDropdown]="accountMenu">Account</button>
 *   <ng-template #accountMenu>
 *     <a routerLink="/profile" class="ax-dropdown-item" axDropdownItem>Profile</a>
 *     <button class="ax-dropdown-item" axDropdownItem (click)="signOut()">Sign out</button>
 *   </ng-template>
 */
@Directive({
  selector: '[axDropdown]',
  standalone: true,
  exportAs: 'axDropdown',
})
export class AxDropdownDirective implements OnDestroy {
  @Input('axDropdown') template: TemplateRef<unknown> | null = null;
  /** Alignment of the menu relative to the trigger ('end' = right-aligned). */
  @Input() axDropdownAlign: AxDropdownAlign = 'end';
  @Input() axDropdownDisabled = false;

  private overlayRef: OverlayRef | null = null;

  private readonly host = inject(ElementRef<HTMLElement>);
  private readonly viewContainerRef = inject(ViewContainerRef);
  private readonly overlay = inject(Overlay);
  private readonly scrollStrategies = inject(ScrollStrategyOptions);

  @HostListener('click', ['$event'])
  onClick(event: MouseEvent): void {
    if (this.axDropdownDisabled || !this.template) return;
    event.stopPropagation();
    this.isOpen ? this.close() : this.open();
  }

  get isOpen(): boolean {
    return !!this.overlayRef;
  }

  open(): void {
    if (this.overlayRef || !this.template) return;

    const positions: ConnectedPosition[] = this.axDropdownAlign === 'end'
      ? [
          { originX: 'end', originY: 'bottom', overlayX: 'end', overlayY: 'top', offsetY: 4 },
          { originX: 'end', originY: 'top', overlayX: 'end', overlayY: 'bottom', offsetY: -4 },
        ]
      : [
          { originX: 'start', originY: 'bottom', overlayX: 'start', overlayY: 'top', offsetY: 4 },
          { originX: 'start', originY: 'top', overlayX: 'start', overlayY: 'bottom', offsetY: -4 },
        ];

    const positionStrategy = this.overlay
      .position()
      .flexibleConnectedTo(this.host)
      .withPositions(positions);

    const config = new OverlayConfig({
      positionStrategy,
      scrollStrategy: this.scrollStrategies.reposition(),
      hasBackdrop: true,
      backdropClass: 'cdk-overlay-transparent-backdrop',
      panelClass: ['ax-dropdown-menu', 'ax-dropdown-menu-open'],
    });

    this.overlayRef = this.overlay.create(config);
    this.overlayRef.overlayElement.setAttribute('role', 'menu');

    const portal = new TemplatePortal(this.template, this.viewContainerRef);
    this.overlayRef.attach(portal);

    this.overlayRef.backdropClick().subscribe(() => this.close());
    this.overlayRef.keydownEvents().subscribe(event => {
      if (event.key === 'Escape') { event.preventDefault(); this.close(); }
    });

    // Listen for clicks on items inside the menu — close on any item click
    this.overlayRef.overlayElement.addEventListener('click', this.onMenuClick);
  }

  close(): void {
    if (!this.overlayRef) return;
    this.overlayRef.overlayElement.removeEventListener('click', this.onMenuClick);
    this.overlayRef.detach();
    this.overlayRef.dispose();
    this.overlayRef = null;
  }

  private onMenuClick = (event: MouseEvent): void => {
    const target = event.target as HTMLElement | null;
    if (!target) return;
    // If the click landed on something with [axDropdownItem] or the .ax-dropdown-item
    // class, close the menu afterwards (unless the item has [axDropdownKeepOpen]).
    const item = target.closest('[axDropdownItem], .ax-dropdown-item');
    if (item && !item.hasAttribute('axDropdownKeepOpen')) {
      setTimeout(() => this.close(), 0);
    }
  };

  ngOnDestroy(): void {
    this.overlayRef?.dispose();
  }
}

/**
 * Marker directive for menu items. Clicking one closes the parent dropdown.
 * Use on <a>, <button>, or any clickable element inside a dropdown template.
 * (The directive itself is optional — .ax-dropdown-item class has the same
 * effect — but it is clearer in templates and future-proofs us if we ever
 * change the trigger logic.)
 */
@Directive({
  selector: '[axDropdownItem]',
  standalone: true,
})
export class AxDropdownItemDirective {}
