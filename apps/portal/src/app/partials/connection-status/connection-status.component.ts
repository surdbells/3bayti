import { ChangeDetectionStrategy, ChangeDetectorRef, Component, OnDestroy, OnInit, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subscription } from 'rxjs';
import { ConnectivityService } from '../../services/connectivity.service';
import { IconComponent } from '../../shared/icon/icon.component';

type BannerState = 'hidden' | 'offline' | 'reconnected';

/**
 * Global connection-status banner. Mounted once at the app root, so it covers
 * every surface, admin, vendor, and the sign-in screen, from a single place.
 *
 * Behaviour:
 *  - Goes offline  → a persistent alert stays until the connection returns.
 *  - Comes back    → a transient "Back online" confirmation, auto-dismissed.
 *  - Fresh load while online → nothing (we only confirm a *recovery*, we don't
 *    greet an already-online session).
 */
@Component({
  selector: 'app-connection-status',
  standalone: true,
  imports: [CommonModule, IconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './connection-status.component.html',
  styleUrl: './connection-status.component.css',
})
export class ConnectionStatusComponent implements OnInit, OnDestroy {
  private readonly connectivity = inject(ConnectivityService);
  private readonly cdr = inject(ChangeDetectorRef);

  private static readonly RECONNECT_VISIBLE_MS = 4_000;

  private sub?: Subscription;
  private reconnectTimer?: ReturnType<typeof setTimeout>;
  private hasBeenOffline = false;

  state: BannerState = 'hidden';

  ngOnInit(): void {
    this.sub = this.connectivity.online$.subscribe((online) => {
      if (!online) {
        this.clearReconnectTimer();
        this.hasBeenOffline = true;
        this.state = 'offline';
      } else if (this.hasBeenOffline) {
        this.hasBeenOffline = false;
        this.state = 'reconnected';
        this.reconnectTimer = setTimeout(() => {
          this.state = 'hidden';
          this.cdr.markForCheck();
        }, ConnectionStatusComponent.RECONNECT_VISIBLE_MS);
      } else {
        this.state = 'hidden';
      }
      this.cdr.markForCheck();
    });
  }

  /** Manual "Retry" from the offline banner. */
  retry(): void {
    this.connectivity.recheck();
  }

  private clearReconnectTimer(): void {
    if (this.reconnectTimer) {
      clearTimeout(this.reconnectTimer);
      this.reconnectTimer = undefined;
    }
  }

  ngOnDestroy(): void {
    this.sub?.unsubscribe();
    this.clearReconnectTimer();
  }
}
