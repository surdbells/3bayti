/**
 * AxAinConciergeSheet, the centered "What can Ain do for you?" sheet.
 *
 * One floating card, centered on screen (NOT a bottom sheet), that lists the
 * four Ain AI tools with a title, a one-line description and a short example
 * each. It replaces the inline concierge cards that used to sit at the top of
 * both homes (logged-in /account and guest /home): those pages now open this
 * sheet from an "Ain" floating action button instead, so the upper section of
 * the page stays clean.
 *
 * USAGE:
 *
 *   <ax-ain-concierge-sheet
 *     [isOpen]="ainSheetOpen"
 *     (dismiss)="ainSheetOpen = false">
 *   </ax-ain-concierge-sheet>
 *
 * Optional [hiddenTools]="['photo']" leaves tools out on a given page (the
 * guest home hides Search by photo).
 *
 * Tapping a tool closes the sheet, emits (dismiss) + (toolSelected) and
 * navigates to the tool's route (the same routes the old inline cards used:
 * /ask-ain, /gift-ain, /outfit, /visual-search).
 *
 * Rendered into a CDK Overlay (like ax-bottom-sheet) so it escapes the page's
 * ion-content stacking context. Accessibility + platform behaviour:
 *   - role="dialog" + aria-modal, labelled by the heading, described by the
 *     intro line; focus moves into the card on open and is trapped there,
 *     then returns to the opener (the FAB) on close.
 *   - Closes on the close button, a backdrop tap, Escape, and the Android
 *     hardware back button (a priority above the homes' BlockerService
 *     handler, which otherwise swallows every back press on those pages).
 *   - Colours come from the ax tokens only, so dark mode follows the OS; the
 *     layout uses logical properties and the chevron flips under [dir=rtl].
 *   - Scale/fade-in respects prefers-reduced-motion.
 */
import {
  ChangeDetectionStrategy,
  Component,
  EventEmitter,
  HostListener,
  Input,
  OnChanges,
  OnDestroy,
  Output,
  SimpleChanges,
  TemplateRef,
  ViewChild,
  ViewContainerRef,
  ViewEncapsulation,
  inject,
} from '@angular/core';
import { DOCUMENT } from '@angular/common';
import { Router } from '@angular/router';
import { Overlay, OverlayRef } from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';
import { FocusTrap, FocusTrapFactory } from '@angular/cdk/a11y';
import { _getFocusedElementPierceShadowDom } from '@angular/cdk/platform';
import { Platform } from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';

import { TranslatePipe } from '../../../translate.pipe';
import { AxIconComponent } from '../icon';

/** Stable key per Ain tool (also used for the per-tool modifier class). */
export type AinToolKey = 'ask' | 'gift' | 'style' | 'photo';

/** One Ain tool row in the sheet. All copy is i18n keys. */
export interface AinConciergeTool {
  key: AinToolKey;
  /** ax-icon name. */
  icon: string;
  /** Router path the tool opens (unchanged from the old inline cards). */
  route: string;
  titleKey: string;
  descKey: string;
  exampleKey: string;
}

/** The four Ain tools, in display order. */
export const AIN_CONCIERGE_TOOLS: readonly AinConciergeTool[] = [
  {
    key: 'ask',
    icon: 'sparkles',
    route: '/ask-ain',
    titleKey: 'ask_ain_title',
    descKey: 'ain_sheet_ask_desc',
    exampleKey: 'ain_sheet_ask_example',
  },
  {
    key: 'gift',
    icon: 'gift',
    route: '/gift-ain',
    titleKey: 'gift_ain_title',
    descKey: 'ain_sheet_gift_desc',
    exampleKey: 'ain_sheet_gift_example',
  },
  {
    key: 'style',
    icon: 'shirt',
    route: '/outfit',
    titleKey: 'account_ain_style_title',
    descKey: 'ain_sheet_style_desc',
    exampleKey: 'ain_sheet_style_example',
  },
  {
    key: 'photo',
    icon: 'camera',
    route: '/visual-search',
    titleKey: 'visual_search_title',
    descKey: 'ain_sheet_photo_desc',
    exampleKey: 'ain_sheet_photo_example',
  },
];

/**
 * Hardware-back priority. Must beat BlockerService (9999), which both homes
 * register to swallow back presses, and Ionic's own overlays (100).
 */
const BACK_BUTTON_PRIORITY = 10000;

/** Exit animation length; keep in sync with the SCSS keyframes. */
const EXIT_MS = 160;

let AX_AIN_SHEET_UID = 0;

@Component({
  selector: 'ax-ain-concierge-sheet',
  standalone: true,
  imports: [TranslatePipe, AxIconComponent],
  templateUrl: './ax-ain-concierge-sheet.component.html',
  styleUrls: ['./ax-ain-concierge-sheet.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  // The card renders into a CDK overlay pane outside this host, and the
  // backdrop/pane classes are set by CDK, so the (ax-ain-sheet prefixed)
  // styles must be global, same as ax-bottom-sheet.
  encapsulation: ViewEncapsulation.None,
})
export class AxAinConciergeSheetComponent implements OnChanges, OnDestroy {
  /** Open state; flip to true to present, false to close. */
  @Input() isOpen = false;

  /** Emits whenever the sheet closes (any reason, including a tool tap). */
  @Output() dismiss = new EventEmitter<void>();

  /** Emits the tool the customer picked, just before navigating to it. */
  @Output() toolSelected = new EventEmitter<AinConciergeTool>();

  /**
   * Tools to leave out of the list on this page. The guest home hides
   * 'photo': its "Take Photo" picker option kills iOS shells shipped without
   * NSCameraUsageDescription (that plist fix ships only with a store build,
   * this sheet ships over OTA), and guests never had that entry point.
   */
  @Input() hiddenTools: readonly AinToolKey[] = [];

  @ViewChild('sheetTemplate', { static: true }) private sheetTemplate!: TemplateRef<unknown>;

  /** The tools shown, in display order (AIN_CONCIERGE_TOOLS minus hiddenTools). */
  tools: readonly AinConciergeTool[] = AIN_CONCIERGE_TOOLS;
  readonly uid = `ax-ain-sheet-${++AX_AIN_SHEET_UID}`;

  private overlay = inject(Overlay);
  private viewContainerRef = inject(ViewContainerRef);
  private focusTrapFactory = inject(FocusTrapFactory);
  private router = inject(Router);
  private platform = inject(Platform);
  private doc = inject(DOCUMENT);

  private overlayRef: OverlayRef | null = null;
  private focusTrap: FocusTrap | null = null;
  private backButtonSub: Subscription | null = null;
  private overlaySubs: Subscription[] = [];
  private previouslyFocusedEl: HTMLElement | null = null;
  private exitTimer: ReturnType<typeof setTimeout> | null = null;

  /** True while the sheet is on screen (overlay attached). */
  get presented(): boolean {
    return this.overlayRef !== null;
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['hiddenTools']) {
      const hidden = new Set(this.hiddenTools ?? []);
      this.tools = AIN_CONCIERGE_TOOLS.filter((tool) => !hidden.has(tool.key));
    }
    if (!changes['isOpen']) {
      return;
    }
    if (this.isOpen) {
      this.present();
    } else if (this.overlayRef) {
      this.close();
    }
  }

  ngOnDestroy(): void {
    this.teardown(false);
  }

  /** Show the sheet (no-op if already shown). */
  present(): void {
    if (this.overlayRef) {
      if (this.exitTimer) {
        // Re-opened mid exit animation: keep the existing card.
        clearTimeout(this.exitTimer);
        this.exitTimer = null;
        this.overlayRef.overlayElement.classList.remove('ax-ain-sheet__panel--closing');
      }
      return;
    }

    // Pierce shadow roots: the opener is usually an ion-fab-button, whose
    // focusable element is the inner .button-native in its shadow DOM;
    // document.activeElement retargets to the (unfocusable, no
    // delegatesFocus) host, so focusing that on close would do nothing.
    this.previouslyFocusedEl = _getFocusedElementPierceShadowDom();

    this.overlayRef = this.overlay.create({
      hasBackdrop: true,
      backdropClass: 'ax-ain-sheet__backdrop',
      panelClass: 'ax-ain-sheet__panel',
      positionStrategy: this.overlay.position().global().centerHorizontally().centerVertically(),
      scrollStrategy: this.overlay.scrollStrategies.block(),
      disposeOnNavigation: true,
    });
    this.overlayRef.attach(new TemplatePortal(this.sheetTemplate, this.viewContainerRef));

    this.overlaySubs.push(
      this.overlayRef.backdropClick().subscribe(() => this.close()),
      // CDK disposes the overlay on its own on a history navigation
      // (disposeOnNavigation); clean up and tell the parent so its isOpen
      // flag resets and the FAB can open the sheet again.
      this.overlayRef.detachments().subscribe(() => {
        if (this.overlayRef) {
          this.teardown(false, false);
          this.dismiss.emit();
        }
      }),
    );

    this.backButtonSub = this.platform.backButton.subscribeWithPriority(BACK_BUTTON_PRIORITY, () => this.close());

    const card = this.overlayRef.overlayElement.querySelector<HTMLElement>('.ax-ain-sheet');
    if (card) {
      this.focusTrap = this.focusTrapFactory.create(card);
      // Focus the dialog itself (tabindex=-1) so screen readers announce its
      // title + intro, and Tab then walks the close button and the tools.
      card.focus({ preventScroll: true });
    }
  }

  /** Close via the close button / backdrop / Escape / hardware back. */
  close(): void {
    if (!this.overlayRef || this.exitTimer) {
      return;
    }
    if (this.prefersReducedMotion()) {
      this.finishClose(true);
      return;
    }
    this.overlayRef.overlayElement.classList.add('ax-ain-sheet__panel--closing');
    this.exitTimer = setTimeout(() => this.finishClose(true), EXIT_MS);
  }

  /** A tool row was tapped: close straight away and open the tool. */
  choose(tool: AinConciergeTool): void {
    if (!this.overlayRef) {
      return;
    }
    // No exit animation and no focus return: the page transition takes over.
    this.teardown(false);
    this.dismiss.emit();
    this.toolSelected.emit(tool);
    void this.router.navigate([tool.route]);
  }

  @HostListener('document:keydown', ['$event'])
  onDocumentKeydown(event: KeyboardEvent): void {
    if (this.overlayRef && (event.key === 'Escape' || event.key === 'Esc')) {
      event.preventDefault();
      this.close();
    }
  }

  private finishClose(restoreFocus: boolean): void {
    this.teardown(restoreFocus);
    this.dismiss.emit();
  }

  /**
   * Release everything the open sheet holds. `disposeRef` is false only when
   * CDK is already disposing the overlay itself (see detachments() above).
   */
  private teardown(restoreFocus: boolean, disposeRef = true): void {
    if (this.exitTimer) {
      clearTimeout(this.exitTimer);
      this.exitTimer = null;
    }
    this.backButtonSub?.unsubscribe();
    this.backButtonSub = null;
    this.focusTrap?.destroy();
    this.focusTrap = null;
    // Drop the subscriptions and the ref BEFORE disposing, so dispose()'s own
    // detachments() emission can't re-enter and emit dismiss a second time.
    const ref = this.overlayRef;
    this.overlayRef = null;
    this.overlaySubs.forEach((sub) => sub.unsubscribe());
    this.overlaySubs = [];
    if (disposeRef) {
      ref?.dispose();
    }

    const opener = this.previouslyFocusedEl;
    this.previouslyFocusedEl = null;
    // isConnected, not document.contains(): contains() does not see into
    // shadow roots, so it is false for the FAB's inner button.
    if (restoreFocus && opener && opener.isConnected) {
      try {
        opener.focus({ preventScroll: true });
      } catch {
        /* noop */
      }
    }
  }

  private prefersReducedMotion(): boolean {
    try {
      return !!this.doc.defaultView?.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
    } catch {
      return false;
    }
  }
}
