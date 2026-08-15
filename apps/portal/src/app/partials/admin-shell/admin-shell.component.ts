import { Component, OnInit, inject } from '@angular/core';
import { AsideComponent } from '../aside/aside.component';
import { AdminTopComponent } from '../admin-top/admin-top.component';
import { IdleModalComponent } from '../idle-modal/idle-modal.component';
import { PermissionService } from '../../services/permission.service';
import { SessionManager } from '../../services/session-manager.service';

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
  imports: [AsideComponent, AdminTopComponent, IdleModalComponent],
  template: `
    <app-aside [isOpen]="nav_open" (isOpenChange)="nav_open = $event"></app-aside>

    <div class="ax-app-shell">
      <app-admin-top (menuToggle)="nav_open = !nav_open"></app-admin-top>
      <main class="ax-main">
        <ng-content></ng-content>
      </main>
    </div>

    <app-idle-modal></app-idle-modal>
  `,
})
export class AdminShellComponent implements OnInit {
  nav_open = false;

  private readonly session = inject(SessionManager);

  constructor(private permissions: PermissionService) {}

  ngOnInit(): void {
    // Load the current user's effective permissions once for UI gating.
    this.permissions.load();
    // Keep the session alive while working; warn + sign out only on idle.
    this.session.start();
  }
}
