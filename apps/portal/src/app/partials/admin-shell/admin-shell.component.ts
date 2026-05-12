import { Component } from '@angular/core';
import { AsideComponent } from '../aside/aside.component';
import { AdminTopComponent } from '../admin-top/admin-top.component';

/**
 * Admin application shell.
 *
 * Parallel to vendor-shell but for admin pages: wraps the aside (admin
 * sidenav), admin-top topbar, and a `<main class="ax-main">` slot
 * containing `<ng-content>`. No bottom nav (admin work is desktop-first).
 *
 * Usage:
 *   <app-admin-shell>
 *     <div class="ax-container">
 *       ...page content...
 *     </div>
 *   </app-admin-shell>
 */
@Component({
  selector: 'app-admin-shell',
  standalone: true,
  imports: [AsideComponent, AdminTopComponent],
  template: `
    <app-aside [isOpen]="nav_open" (isOpenChange)="nav_open = $event"></app-aside>

    <div class="ax-app-shell">
      <app-admin-top (menuToggle)="nav_open = !nav_open"></app-admin-top>
      <main class="ax-main">
        <ng-content></ng-content>
      </main>
    </div>
  `,
})
export class AdminShellComponent {
  nav_open = false;
}
