/**
 * AxBottomSheet — premium FLOATING + SEARCHABLE bottom-sheet primitive.
 *
 * Replaces <ion-modal> usage for bottom-sheet patterns across the app.
 * Renders into a CDK Overlay so the sheet is not constrained by the
 * parent's overflow context. M2 token-driven styling.
 *
 * By default the sheet is a PREMIUM FLOATING card: inset from the screen
 * edges (horizontal + bottom margin), all four corners rounded, centered
 * with a max-width, and a content-ish default height (NOT full-screen).
 * For sheets that genuinely need full height (e.g. the styles/create
 * product picker), opt out with [fullscreen]="true" (alias [inset]="false")
 * to restore the edge-to-edge, top-rounded, full-height behaviour.
 *
 * USAGE (floating list picker with built-in search):
 *
 *   <ax-bottom-sheet
 *     [isOpen]="isOpen"
 *     (didDismiss)="isOpen = false"
 *     title="Select country"
 *     [searchable]="true"
 *     [searchPlaceholder]="'Search…'"
 *     (searchChange)="query = $event">
 *
 *     <ul>
 *       @for (item of filtered(query); track item) { <li>…</li> }
 *     </ul>
 *   </ax-bottom-sheet>
 *
 * USAGE (full-screen opt-out):
 *
 *   <ax-bottom-sheet [isOpen]="isOpen" [fullscreen]="true" …>
 *
 * SLOTS:
 *   - default: body content
 *   - [ax-sheet-header]: optional custom header (overrides the title prop)
 *   - [ax-sheet-footer]: optional sticky footer (e.g. for a primary CTA)
 *
 * INPUTS:
 *   - isOpen            two-way-able boolean to show/hide
 *   - inset             boolean, default true — premium floating mode
 *                       (inset margins, all-corners rounded, max-width)
 *   - fullscreen        boolean, default false — convenience alias for
 *                       [inset]="false" (edge-to-edge full-height sheet)
 *   - snapPoints        number[] of fractions (0-1) of viewport height.
 *                       Defaults to [0.6, 0.85] in floating mode and [1]
 *                       in fullscreen mode (a content-height default that
 *                       is intentionally NOT full-screen).
 *   - initialSnap       index into snapPoints, defaults to 0
 *   - backdropDismiss   boolean, default true
 *   - swipeToClose      boolean, default true
 *   - showHandle        boolean, default true (show the drag handle pill)
 *   - title             string (optional inline title)
 *   - showCloseButton   boolean, default true when title is set
 *   - searchable        boolean, default false — render a built-in native
 *                       search input below the header
 *   - searchPlaceholder string placeholder for the search input
 *   - searchQuery       two-way-able current query string
 *
 * OUTPUTS:
 *   - didDismiss      emits when sheet is closed (any reason)
 *   - didPresent      emits when open animation completes
 *   - searchChange    emits the search query on every keystroke (also
 *                     supports [(searchQuery)] two-way binding)
 *
 * NOTES:
 *   - SEARCH INPUT CONSTRAINT: the built-in search MUST be a plain NATIVE
 *     <input type="text"> styled to match the design system. Do NOT use
 *     <ion-searchbar> / <ion-select> (or any Ionic overlay-backed control)
 *     inside this sheet — Ionic overlay components render their popovers in
 *     a separate overlay layer that lands BEHIND this CDK overlay, producing
 *     the documented z-index trap. Native inputs render inline and are safe.
 *   - Drag-to-close uses pointer events for cross-input support.
 *   - Below-first-snap drag dismisses the sheet.
 *   - Backdrop tap, ESC, and swipe-down all dismiss (when allowed).
 *   - Body scroll is locked while open via CDK BlockScrollStrategy.
 *   - Focus is auto-trapped within the sheet via CDK FocusTrap.
 *   - Respects prefers-reduced-motion.
 *   - safe-area-inset-bottom padding for notched devices.
 */
import {
  Component,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  ElementRef,
  EventEmitter,
  Inject,
  Input,
  OnChanges,
  OnDestroy,
  Output,
  SimpleChanges,
  TemplateRef,
  ViewChild,
  ViewContainerRef,
  ViewEncapsulation,
  HostListener,
  AfterViewInit,
  inject,
} from '@angular/core';
import { CommonModule, DOCUMENT } from '@angular/common';
import { Overlay, OverlayRef, BlockScrollStrategy } from '@angular/cdk/overlay';
import { TemplatePortal } from '@angular/cdk/portal';
import { FocusTrap, FocusTrapFactory } from '@angular/cdk/a11y';
import { ESCAPE } from '@angular/cdk/keycodes';

import { AxIconComponent } from '../icon';

let AX_SHEET_UID = 0;

@Component({
  selector: 'ax-bottom-sheet',
  standalone: true,
  imports: [CommonModule, AxIconComponent],
  templateUrl: './ax-bottom-sheet.component.html',
  styleUrls: ['./ax-bottom-sheet.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush,
  encapsulation: ViewEncapsulation.None,
})
export class AxBottomSheetComponent implements OnChanges, OnDestroy, AfterViewInit {
  /** ===== Inputs ===== */
  @Input() isOpen = false;

  /**
   * Caller-supplied snap points. When left null the component picks a
   * sensible default per mode (see `effectiveSnapPoints`): a content-ish
   * [0.6, 0.85] in floating mode, or [1] in fullscreen mode.
   */
  @Input() snapPoints: number[] | null = null;

  @Input() initialSnap = 0;

  @Input() backdropDismiss = true;

  @Input() swipeToClose = true;

  @Input() showHandle = true;

  @Input() title: string | null = null;

  @Input() showCloseButton: boolean | null = null;

  /**
   * Premium floating mode (default). When true the sheet is inset from the
   * screen edges, rounded on all four corners and centered with a max-width.
   * Set false (or use [fullscreen]="true") to render a full-height,
   * edge-to-edge sheet rounded only on the top corners.
   */
  @Input() inset = true;

  /** Convenience alias for [inset]="false". */
  @Input()
  set fullscreen(value: boolean) {
    this.inset = !value;
  }
  get fullscreen(): boolean {
    return !this.inset;
  }

  /** Render a built-in native search input below the header. */
  @Input() searchable = false;

  /** Placeholder for the built-in search input. */
  @Input() searchPlaceholder = '';

  /** Current search query (supports [(searchQuery)] two-way binding). */
  @Input() searchQuery = '';

  /** Optional aria-label (used when no title is set). */
  @Input() ariaLabel = 'Bottom sheet';

  /** ===== Outputs ===== */
  @Output() didDismiss = new EventEmitter<void>();
  @Output() didPresent = new EventEmitter<void>();
  @Output() searchChange = new EventEmitter<string>();
  @Output() searchQueryChange = new EventEmitter<string>();

  /**
   * Emitted once when the body scrolls within ~120px of the bottom, then
   * latches until the user scrolls back up. Lets consumers drive
   * infinite-scroll / load-more — ion-infinite-scroll does NOT work inside
   * this sheet because the body is a plain scroll container, not an
   * <ion-content>. Additive + optional: existing consumers are unaffected.
   */
  @Output() scrolledToBottom = new EventEmitter<void>();

  /** ===== Refs ===== */
  @ViewChild('sheetTemplate', { static: true }) private sheetTemplate!: TemplateRef<unknown>;

  /** ===== State ===== */
  readonly sheetId = `ax-sheet-${++AX_SHEET_UID}`;

  /** Currently active snap index. Updated as user drags. */
  activeSnap = 0;

  /** Translate-Y in pixels applied during a drag (additive over snap base). */
  dragOffsetPx = 0;

  /** Whether a pointer drag is currently in progress. */
  private isDragging = false;
  private dragStartY = 0;
  private dragStartOffset = 0;
  private pointerId: number | null = null;
  private dragVelocityY = 0;
  private lastDragY = 0;
  private lastDragTime = 0;

  private overlayRef: OverlayRef | null = null;
  private focusTrap: FocusTrap | null = null;
  private previouslyFocusedEl: HTMLElement | null = null;

  /** ===== DI ===== */
  private overlay = inject(Overlay);
  private viewContainerRef = inject(ViewContainerRef);
  private focusTrapFactory = inject(FocusTrapFactory);
  private cdr = inject(ChangeDetectorRef);
  private hostEl = inject(ElementRef<HTMLElement>);

  constructor(@Inject(DOCUMENT) private doc: Document) {}

  /** ===== Lifecycle ===== */
  ngAfterViewInit(): void {
    if (this.isOpen) {
      this.present();
    }
  }

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['isOpen'] && !changes['isOpen'].firstChange) {
      const open = changes['isOpen'].currentValue;
      if (open) {
        this.present();
      } else {
        this.dismiss();
      }
    }
  }

  ngOnDestroy(): void {
    this.dispose();
  }

  /** ===== Public API ===== */

  /** Imperatively open the sheet. Same as setting isOpen=true. */
  present(): void {
    if (this.overlayRef) return;

    const scrollStrategy: BlockScrollStrategy = this.overlay.scrollStrategies.block();

    this.overlayRef = this.overlay.create({
      hasBackdrop: true,
      backdropClass: 'ax-sheet__backdrop',
      panelClass: 'ax-sheet__panel',
      positionStrategy: this.overlay.position()
        .global()
        .bottom('0')
        .left('0')
        .width('100%'),
      scrollStrategy,
      disposeOnNavigation: true,
    });

    // Backdrop dismiss
    this.overlayRef.backdropClick().subscribe(() => {
      if (this.backdropDismiss) {
        this.requestDismiss();
      }
    });

    // Save current focus, attach portal, then trap focus inside.
    this.previouslyFocusedEl = this.doc.activeElement as HTMLElement | null;

    // Configure snap state BEFORE attaching the portal so the first render
    // already reflects the correct height.
    this.activeSnap = Math.max(0, Math.min(this.initialSnap, this.effectiveSnapPoints.length - 1));
    this.dragOffsetPx = 0;

    const portal = new TemplatePortal(this.sheetTemplate, this.viewContainerRef);
    this.overlayRef.attach(portal);

    // Set up focus trap on the actual sheet element after the next frame
    requestAnimationFrame(() => {
      const sheetEl = this.overlayRef?.overlayElement.querySelector<HTMLElement>('.ax-sheet');
      if (sheetEl) {
        this.focusTrap = this.focusTrapFactory.create(sheetEl);
        this.focusTrap.focusInitialElementWhenReady().then(() => {
          this.didPresent.emit();
        });
      }
      this.cdr.markForCheck();
    });
  }

  /** Imperatively close the sheet. Emits didDismiss. */
  dismiss(): void {
    if (!this.overlayRef) return;
    this.dispose();
    this.didDismiss.emit();
  }

  /** ===== Internal ===== */

  /** Like dismiss() but only fires when allowed (e.g. backdrop tap respects backdropDismiss). */
  private requestDismiss(): void {
    this.dismiss();
  }

  private dispose(): void {
    if (this.focusTrap) {
      this.focusTrap.destroy();
      this.focusTrap = null;
    }
    if (this.overlayRef) {
      this.overlayRef.dispose();
      this.overlayRef = null;
    }
    if (this.previouslyFocusedEl && this.doc.contains(this.previouslyFocusedEl)) {
      try {
        this.previouslyFocusedEl.focus();
      } catch {
        /* noop */
      }
    }
    this.previouslyFocusedEl = null;
    this.isDragging = false;
    this.dragOffsetPx = 0;
    this.pointerId = null;
  }

  /**
   * Resolved snap points. Honours a caller-supplied [snapPoints]; otherwise
   * falls back to a sensible per-mode default — a content-ish [0.6, 0.85] in
   * floating mode (intentionally NOT full-screen) or [1] in fullscreen mode.
   */
  get effectiveSnapPoints(): number[] {
    if (this.snapPoints && this.snapPoints.length > 0) {
      return this.snapPoints;
    }
    return this.inset ? [0.6, 0.85] : [1];
  }

  /** Sheet height as a vh number, derived from the active snap point.
   *  Snap point of 0.5 -> 50vh, 0.9 -> 90vh, 1 -> 100vh. */
  get sheetHeightVh(): number {
    const points = this.effectiveSnapPoints;
    const snapFrac = points[this.activeSnap] ?? points[points.length - 1] ?? 1;
    return Math.max(10, Math.min(100, snapFrac * 100));
  }

  /** ===== Search ===== */
  onSearchInput(event: Event): void {
    const value = (event.target as HTMLInputElement).value;
    this.searchQuery = value;
    this.searchChange.emit(value);
    this.searchQueryChange.emit(value);
    this.cdr.markForCheck();
  }

  clearSearch(): void {
    this.searchQuery = '';
    this.searchChange.emit('');
    this.searchQueryChange.emit('');
    this.cdr.markForCheck();
  }

  /** ===== Body scroll (infinite-scroll trigger) ===== */

  /** Latch so scrolledToBottom fires once per bottom-approach, not per event. */
  private nearBottomLatched = false;

  onBodyScroll(event: Event): void {
    const el = event.target as HTMLElement;
    const remaining = el.scrollHeight - el.scrollTop - el.clientHeight;
    if (remaining <= 120) {
      if (!this.nearBottomLatched) {
        this.nearBottomLatched = true;
        this.scrolledToBottom.emit();
      }
    } else {
      this.nearBottomLatched = false;
    }
  }

  /** ===== Drag handling ===== */

  onPointerDown(event: PointerEvent): void {
    if (!this.swipeToClose) return;

    // Only initiate drag from handle / header area; CSS class on target is the cue
    const target = event.target as HTMLElement;
    if (!target.closest('.ax-sheet__drag-zone')) return;

    this.isDragging = true;
    this.pointerId = event.pointerId;
    this.dragStartY = event.clientY;
    this.dragStartOffset = this.dragOffsetPx;
    this.lastDragY = event.clientY;
    this.lastDragTime = performance.now();
    this.dragVelocityY = 0;

    // Capture the pointer so we keep getting events even if the user drags off
    (event.target as HTMLElement).setPointerCapture?.(event.pointerId);
  }

  onPointerMove(event: PointerEvent): void {
    if (!this.isDragging || event.pointerId !== this.pointerId) return;

    const deltaY = event.clientY - this.dragStartY;
    // Only allow drag downward beyond start (no rubber-band up)
    const next = this.dragStartOffset + Math.max(0, deltaY);

    // Update velocity for fling detection
    const now = performance.now();
    const dt = now - this.lastDragTime;
    if (dt > 0) {
      this.dragVelocityY = (event.clientY - this.lastDragY) / dt; // px per ms
      this.lastDragY = event.clientY;
      this.lastDragTime = now;
    }

    this.dragOffsetPx = next;
    this.cdr.markForCheck();
  }

  onPointerUp(event: PointerEvent): void {
    if (!this.isDragging || event.pointerId !== this.pointerId) return;
    this.isDragging = false;
    this.pointerId = null;

    // Decision: dismiss, snap to current, or snap to lower snap point.
    const FLING_THRESHOLD = 0.5;       // px/ms
    const DRAG_DISMISS_PX = 140;       // px of drag = dismiss

    if (this.dragVelocityY > FLING_THRESHOLD || this.dragOffsetPx > DRAG_DISMISS_PX) {
      // Dismiss outright if at lowest snap, otherwise step down one snap point.
      if (this.activeSnap === 0) {
        this.dragOffsetPx = 0;
        this.requestDismiss();
        return;
      }
      this.activeSnap = Math.max(0, this.activeSnap - 1);
    }

    // Animate offset back to 0
    this.dragOffsetPx = 0;
    this.cdr.markForCheck();
  }

  onCloseClick(): void {
    this.requestDismiss();
  }

  onBackdropTap(): void {
    if (this.backdropDismiss) this.requestDismiss();
  }

  /** ===== Keyboard ===== */
  @HostListener('document:keydown', ['$event'])
  onKeydown(event: KeyboardEvent): void {
    if (!this.overlayRef) return;
    if (event.keyCode === ESCAPE) {
      event.preventDefault();
      this.requestDismiss();
    }
  }
}
