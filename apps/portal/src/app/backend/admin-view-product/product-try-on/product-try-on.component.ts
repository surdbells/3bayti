import { Component, DestroyRef, Input, OnChanges, SimpleChanges, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';

import { TryOnAdminService, TryOnEligibility } from '../../../services/try-on-admin.service';
import { PermissionService } from '../../../services/permission.service';
import { HotToastService } from '../../../shared/toast/toast.service';
import { I18nService } from '../../../i18n.service';
import { TranslatePipe } from '../../../translate.pipe';
import { IconComponent } from '../../../shared/icon/icon.component';
import { AxTooltipDirective } from '../../../shared/overlays/ax-tooltip.directive';
import {
  EligibilityBadge,
  ToggleState,
  Translate,
  eligibilityBadge,
  eligibilityFromError,
  eligibilityHintKey,
  enabledDelta,
  toggleErrorMessage,
  toggleState,
} from '../../admin-try-on/try-on-ui';

/**
 * "Virtual Try-On" card on the admin product page: the product's
 * try_on_enabled switch (PUT /admin/products/:id/try-on, v3 id) plus why its
 * main image can or can't be used.
 *
 * Fed from the admin product payload: the RAW flag (`try_on_enabled`),
 * `try_on_available` and `try_on_eligibility`. Shown only while Try-On is
 * available; when the payload predates `try_on_available` the session-cached
 * GET /admin/try-on/status decides (hidden while unknown). Switching needs
 * products.edit; the write is optimistic and rolled back with an error toast
 * on failure (a refused enable shows the localized reason and also updates
 * the eligibility shown).
 */
@Component({
  selector: 'app-product-try-on',
  standalone: true,
  imports: [CommonModule, RouterLink, TranslatePipe, IconComponent, AxTooltipDirective],
  templateUrl: './product-try-on.component.html',
  styleUrl: './product-try-on.component.css',
})
export class ProductTryOnComponent implements OnChanges {
  private readonly svc = inject(TryOnAdminService);
  private readonly perms = inject(PermissionService);
  private readonly toast = inject(HotToastService);
  private readonly i18n = inject(I18nService);
  private readonly destroyRef = inject(DestroyRef);

  /** v3 product id. */
  @Input({ required: true }) productId = 0;
  /** Product name, for the confirmation toast. */
  @Input() productName = '';
  /** Raw stored flag from the admin payload. */
  @Input() enabled = false;
  /** `try_on_available` from the admin payload; null = not sent (use the status endpoint). */
  @Input() available: boolean | null = null;
  /** `try_on_eligibility` from the admin payload; null = unknown. */
  @Input() eligibility: TryOnEligibility | null = null;

  /** Current (optimistic while saving) switch state + eligibility. */
  isOn = false;
  currentEligibility: TryOnEligibility | null = null;
  busy = false;

  /** Bumped by every save and product switch: only the latest save lands. */
  private seq = 0;

  readonly t: Translate = (key, params) => this.i18n.t(key, params);

  ngOnChanges(changes: SimpleChanges): void {
    if (changes['productId']) {
      // A save still running for the previous product doesn't belong here.
      this.seq++;
      this.busy = false;
    }
    if (!this.busy && (changes['enabled'] || changes['eligibility'] || changes['productId'])) {
      this.isOn = this.enabled;
      this.currentEligibility = this.eligibility;
    }
    if (this.available === null) this.svc.ensureStatus();
  }

  /** Show the card: the payload's availability, else the cached status. */
  get visible(): boolean {
    return this.productId > 0 && (this.available ?? this.svc.available());
  }

  get canEdit(): boolean {
    return this.perms.can('products.edit');
  }

  get state(): ToggleState {
    return toggleState(this.isOn, this.currentEligibility, this.canEdit, this.busy);
  }

  get badge(): EligibilityBadge {
    return eligibilityBadge(this.currentEligibility);
  }

  /** The rule (eligible / unknown) or why the image can't be used. */
  get eligibilityMessage(): string {
    return this.t(eligibilityHintKey(this.currentEligibility));
  }

  /** Enabled, but customers still won't see the button (image ineligible). */
  get enabledButBlocked(): boolean {
    return this.isOn && !!this.currentEligibility && !this.currentEligibility.eligible;
  }

  get toggleHint(): string {
    const key = this.state.hintKey;
    return key ? this.t(key) : '';
  }

  toggle(ev?: Event): void {
    const prev = this.isOn;
    if (this.state.disabled || !this.productId) {
      const input = ev?.target as HTMLInputElement | null;
      if (input) input.checked = prev;
      return;
    }
    const next = !prev;
    const eligibility = this.currentEligibility;
    const productId = this.productId;
    const seq = ++this.seq;
    this.isOn = next;
    this.busy = true;
    this.svc.setEnabled(productId, next).pipe(takeUntilDestroyed(this.destroyRef)).subscribe({
      next: (p) => {
        if (seq !== this.seq) return;
        this.busy = false;
        this.isOn = p.try_on_enabled;
        this.currentEligibility = p.eligibility ?? eligibility;
        this.svc.bumpEnabledCount(enabledDelta(prev, p.try_on_enabled));
        const name = this.productName || p.name;
        this.toast.success(this.t(p.try_on_enabled ? 'tryon.toggle.enabled' : 'tryon.toggle.disabled', { name }));
      },
      error: (err: unknown) => {
        if (seq !== this.seq) return;
        this.busy = false;
        this.isOn = prev;
        this.currentEligibility = eligibilityFromError(err) ?? eligibility;
        this.toast.error(toggleErrorMessage(err, this.t));
      },
    });
  }
}
