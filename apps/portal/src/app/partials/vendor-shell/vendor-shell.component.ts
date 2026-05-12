import { Component } from '@angular/core';
import { SideComponent } from '../side/side.component';
import { TopComponent } from '../top/top.component';
import { BottomNavComponent } from '../bottom-nav/bottom-nav.component';

/**
 * Vendor application shell.
 *
 * Wraps the sidenav, topbar, bottom-nav (mobile), and a
 * `<main class="ax-main">` slot containing `<ng-content>`.
 *
 * Owns the mobile drawer open/close state internally — the topbar's
 * hamburger button toggles it via `(menuToggle)`, and the sidenav reads it
 * via `[isOpen]`. (On phones/tablets the hamburger is hidden and bottom nav
 * is the primary navigation.)
 *
 * Replaces the long-form `<app-side> + .ax-app-shell + <app-top> + <main>`
 * pattern that was used inconsistently across vendor pages and that left the
 * hamburger non-functional whenever the per-page wiring was forgotten.
 *
 * Usage:
 *   <app-vendor-shell>
 *     <div class="ax-container">
 *       ...page content...
 *     </div>
 *   </app-vendor-shell>
 */
@Component({
  selector: 'app-vendor-shell',
  standalone: true,
  imports: [SideComponent, TopComponent, BottomNavComponent],
  template: `
    <app-side [isOpen]="nav_open" (isOpenChange)="nav_open = $event"></app-side>

    <div class="ax-app-shell">
      <app-top (menuToggle)="nav_open = !nav_open"></app-top>
      <main class="ax-main ax-main-with-bottom-nav">
        <ng-content></ng-content>
      </main>
    </div>

    <app-bottom-nav></app-bottom-nav>
  `,
})
export class VendorShellComponent {
  nav_open = false;
}
