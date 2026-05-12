// blocker.service.ts
import { Injectable } from '@angular/core';
import { Platform } from '@ionic/angular';

@Injectable({
  providedIn: 'root'
})
export class BlockerService {
  // reference count so multiple callers can request block() safely
  private blockCount = 0;

  // saved original swipeGesture state so we can restore it
  private originalSwipeState: boolean | null = null;

  // the high-priority subscription/handler returned by platform.backButton
  private backBtnSubscription: { unsubscribe?: () => void } | null = null;

  // priority used for back button subscription (high so it overrides nav)
  private readonly BACK_PRIORITY = 9999;

  constructor(private platform: Platform) {}

  /**
   * Block navigation gestures and hardware back.
   * Call multiple times from different components — first call applies the block,
   * subsequent calls increment refcount. Call `unblock()` to release.
   *
   * @param opts.disableSwipe - whether to disable swipe-to-go-back (default: true)
   * @param opts.disableHardwareBack - whether to block hardware back (default: true)
   */
  block(opts: { disableSwipe?: boolean; disableHardwareBack?: boolean } = {}) {
    const { disableSwipe = true, disableHardwareBack = true } = opts;

    // increment reference count
    this.blockCount++;
    if (this.blockCount > 1) {
      // already blocked; nothing else to do
      return;
    }

    // DISABLE SWIPE
    if (disableSwipe) {
      try {
        const outlet = document.querySelector('ion-router-outlet') as any;
        if (outlet) {
          // save original state (if not saved)
          if (this.originalSwipeState === null && typeof outlet.swipeGesture !== 'undefined') {
            this.originalSwipeState = !!outlet.swipeGesture;
          }
          // set disabled
          if (typeof outlet.swipeGesture !== 'undefined') {
            outlet.swipeGesture = false;
          } else {
            // fallback: some older outlets might use nativeEl - still try
            const native = (outlet as any).nativeElement ?? outlet;
            if (native && typeof native.swipeGesture !== 'undefined') {
              native.swipeGesture = false;
            } else {
              console.warn('BlockerService: unable to set swipeGesture on ion-router-outlet (not found).');
            }
          }
        } else {
          console.warn('BlockerService: ion-router-outlet element not found in DOM.');
        }
      } catch (err) {
        console.warn('BlockerService: error while disabling swipeGesture', err);
      }
    }

    // DISABLE HARDWARE BACK (Android)
    if (disableHardwareBack) {
      try {
        // subscribeWithPriority returns a subscription-like object with unsubscribe()
        this.backBtnSubscription = this.platform.backButton.subscribeWithPriority(
          this.BACK_PRIORITY,
          () => {
            // no-op: block navigation
            // Optionally consider showing a toast/confirm here
            console.debug('BlockerService: hardware back pressed (blocked).');
          }
        );
      } catch (err) {
        console.warn('BlockerService: error while subscribing to backButton', err);
      }
    }
  }

  /**
   * Releases a single block. When the refcount reaches 0 the original behavior is restored.
   */
  unblock() {
    if (this.blockCount <= 0) {
      // nothing to do
      this.blockCount = 0;
      return;
    }

    this.blockCount--;
    if (this.blockCount > 0) {
      // still blocked somewhere else
      return;
    }

    // restore swipe state
    try {
      const outlet = document.querySelector('ion-router-outlet') as any;
      if (outlet) {
        if (this.originalSwipeState !== null && typeof outlet.swipeGesture !== 'undefined') {
          outlet.swipeGesture = this.originalSwipeState;
        } else if (typeof outlet.swipeGesture !== 'undefined') {
          // default to true if we don't know original
          outlet.swipeGesture = true;
        } else {
          const native = (outlet as any).nativeElement ?? outlet;
          if (native && typeof native.swipeGesture !== 'undefined' && this.originalSwipeState !== null) {
            native.swipeGesture = this.originalSwipeState;
          }
        }
      }
    } catch (err) {
      console.warn('BlockerService: error while restoring swipeGesture', err);
    } finally {
      this.originalSwipeState = null;
    }

    // remove back button handler
    try {
      if (this.backBtnSubscription && typeof this.backBtnSubscription.unsubscribe === 'function') {
        this.backBtnSubscription.unsubscribe();
      }
      this.backBtnSubscription = null;
    } catch (err) {
      console.warn('BlockerService: error while unsubscribing backButton', err);
    }
  }

  /**
   * Force clear everything immediately (useful for emergency cleanup)
   */
  forceUnblockAll() {
    this.blockCount = 0;
    this.unblock();
  }

  /**
   * Query whether navigation is currently blocked by this service.
   */
  isBlocked(): boolean {
    return this.blockCount > 0;
  }
}
