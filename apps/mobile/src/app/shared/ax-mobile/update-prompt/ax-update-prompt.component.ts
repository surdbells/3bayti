/**
 * AxUpdatePromptComponent — centered floating modal that blocks app usage
 * until the user updates. Used when AppUpdateService.check() returns
 * shouldForceUpdate=true.
 *
 * UX:
 *   - Centered modal, not a bottom sheet — visually distinct from the
 *     dismissible sheets used elsewhere
 *   - No backdrop tap to dismiss, no Escape key, no Later button
 *   - "Update now" is the only action
 *   - Above all other UI (z-index 9999) so it can't be obscured
 *   - Respects safe-area inset on iOS (Dynamic Island, notch)
 *
 * Inputs:
 *   isOpen — whether to render the modal
 *   title — heading text (already localized)
 *   message — body text (already localized)
 *   buttonLabel — CTA label (already localized)
 *
 * Outputs:
 *   updateClicked — fires when user taps the CTA. Caller should call
 *                   AppUpdateService.startUpdate() in response.
 *
 * Why no AxBottomSheet reuse: the existing sheet has dismiss affordances
 * (handle, backdrop tap) that contradict the hard-prompt requirement. A
 * separate component is clearer than gutting AxBottomSheet's UX.
 */

import {
  ChangeDetectionStrategy,
  Component,
  EventEmitter,
  Input,
  Output,
} from '@angular/core';
import { CommonModule } from '@angular/common';

import { AxIconComponent } from '../icon';

@Component({
  selector: 'ax-update-prompt',
  standalone: true,
  imports: [CommonModule, AxIconComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './ax-update-prompt.component.html',
  styleUrl: './ax-update-prompt.component.scss',
})
export class AxUpdatePromptComponent {
  /** Whether to render the modal. Caller toggles this. */
  @Input() isOpen = false;

  /** Heading text. Localize before passing in. */
  @Input() title = '';

  /** Body text. Localize before passing in. */
  @Input() message = '';

  /** CTA label. Localize before passing in. */
  @Input() buttonLabel = '';

  /** Optional version string shown as a subdued line under the title.
   *  Set to falsy to hide. */
  @Input() versionLabel = '';

  /** Whether to show a secondary "Later" button below the primary CTA.
   *  When false (default — hard-prompt mode), the modal has no dismiss
   *  affordance and the user must update. When true (soft-prompt mode),
   *  a Later button appears. Caller sets based on AppUpdateService's
   *  UpdateCheckResult.canDismiss. */
  @Input() canDismiss = false;

  /** Label for the Later button. Localize before passing in. Only
   *  meaningful when canDismiss=true. */
  @Input() dismissLabel = '';

  /** Fires when the user taps the CTA. Caller starts the update flow. */
  @Output() updateClicked = new EventEmitter<void>();

  /** Fires when the user taps Later (only emitted in soft-prompt mode).
   *  Caller should call AppUpdateService.markDismissed(version) and
   *  hide the prompt. */
  @Output() dismissed = new EventEmitter<void>();

  onUpdateClick(): void {
    this.updateClicked.emit();
  }

  onDismissClick(): void {
    this.dismissed.emit();
  }
}
