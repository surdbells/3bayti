import { Injectable, inject } from '@angular/core';
import { Location } from '@angular/common';
import { NavigationEnd, Router } from '@angular/router';
import { filter } from 'rxjs';

/**
 * Powers "smart" back buttons.
 *
 * The problem: most `goBack()` handlers called `router.navigate(['/list'])`,
 * which is a NEW forward navigation, it re-initialises the destination
 * (re-fetch, scroll to top, filters reset), so the user never lands back where
 * they were. It also defeats the router's scroll-position restoration, which
 * only kicks in on a real browser back (popstate).
 *
 * `back(fallback)` instead performs a genuine browser back when there's in-app
 * history to return to (so scroll restoration returns the user to the exact
 * spot), and only falls back to `router.navigate([fallback])` when the user
 * arrived directly on this screen (deep link / first load) and there's nothing
 * to go back to.
 */
@Injectable({ providedIn: 'root' })
export class NavigationHistoryService {
  private readonly router = inject(Router);
  private readonly location = inject(Location);

  /** How many in-app navigations have completed this session. */
  private navigations = 0;

  constructor() {
    this.router.events
      .pipe(filter((e) => e instanceof NavigationEnd))
      .subscribe(() => { this.navigations += 1; });
  }

  /**
   * Return to the previous in-app page (restoring its scroll position), or
   * navigate to `fallback` when there's no in-app history to return to.
   */
  back(fallback: string): void {
    if (this.navigations > 1) {
      this.location.back();
    } else {
      this.router.navigate([fallback]);
    }
  }
}
