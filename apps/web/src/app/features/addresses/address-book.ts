import {
  Component,
  ChangeDetectionStrategy,
  inject,
  signal,
  computed,
  OnInit,
} from '@angular/core';
import { NgIf, NgFor } from '@angular/common';
import { RouterLink } from '@angular/router';
import { TranslatePipe } from '@ngx-translate/core';
import { AddressService } from '../../core/addresses';
import type { Address } from '../../core/addresses';
import { AddressFormComponent } from './address-form';
import { ToastService } from '../../shared/forms';

/**
 * Address book page — /account/addresses.
 *
 * Renders the user's saved addresses, with actions to add, edit,
 * delete, or set as default. Used standalone via the account menu
 * AND embedded by Y.2-D's checkout shipping step.
 *
 * Modal-style add/edit
 * --------------------
 * The AddressFormComponent renders inline inside an in-page panel
 * rather than a true modal overlay. This is intentional:
 *   - The form is long enough that a modal would dominate the
 *     viewport on mobile anyway
 *   - In-page panels are easier to animate cleanly and skip the
 *     focus-trap complexity
 *   - Y.2-D will reuse this component inside the checkout layout
 *     where a modal would be especially awkward
 *
 * The panel state is one of:
 *   - 'list'   → just the address list (default)
 *   - 'create' → list + new-address form
 *   - 'edit'   → list + edit-existing form (with the address)
 *
 * Empty state
 * -----------
 * If the user has no addresses, the page shows a centred call-to-add
 * with the form already expanded (saves a click). The form's cancel
 * button is still wired to return to the empty state.
 */
@Component({
  selector: 'app-address-book',
  standalone: true,
  imports: [NgIf, NgFor, RouterLink, TranslatePipe, AddressFormComponent],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <main class="address-book" data-testid="address-book-page">
      <div class="address-book__container">
        <header class="address-book__header">
          <h1 class="address-book__title">
            {{ 'addresses.title' | translate }}
          </h1>
          <p class="address-book__subtitle">
            {{ 'addresses.subtitle' | translate }}
          </p>
        </header>

        <ng-container *ngIf="mode() === 'list'">
          <ng-container *ngIf="addresses().length > 0; else emptyState">
            <div class="address-book__list" role="list">
              <article
                *ngFor="let addr of addresses(); trackBy: trackById"
                class="address-card"
                role="listitem"
                [class.address-card--default]="addr.is_default_shipping"
                data-testid="address-card"
              >
                <header class="address-card__header">
                  <h2 class="address-card__label">
                    {{ addr.label || ('addresses.unlabeled' | translate) }}
                  </h2>
                  <span
                    *ngIf="addr.is_default_shipping"
                    class="address-card__badge"
                    data-testid="address-default-badge"
                  >
                    {{ 'addresses.defaultBadge' | translate }}
                  </span>
                </header>
                <p class="address-card__name">{{ addr.recipient_name }}</p>
                <p class="address-card__line">
                  <ng-container *ngIf="addr.street_address !== null">
                    {{ addr.street_address }},
                  </ng-container>
                  <ng-container *ngIf="addr.building_details !== null">
                    {{ addr.building_details }},
                  </ng-container>
                  {{ addr.area }}, {{ addr.emirate }}
                  <ng-container *ngIf="addr.postal_code !== null">
                    — {{ addr.postal_code }}
                  </ng-container>
                </p>
                <p class="address-card__phone">{{ addr.recipient_phone }}</p>

                <div class="address-card__actions">
                  <button
                    type="button"
                    class="address-card__action"
                    (click)="onEdit(addr)"
                    [attr.data-testid]="'address-edit-' + addr.id"
                  >
                    {{ 'addresses.actions.edit' | translate }}
                  </button>
                  <button
                    *ngIf="!addr.is_default_shipping"
                    type="button"
                    class="address-card__action"
                    [disabled]="isLoading()"
                    (click)="onSetDefault(addr)"
                    [attr.data-testid]="'address-set-default-' + addr.id"
                  >
                    {{ 'addresses.actions.setDefault' | translate }}
                  </button>
                  <button
                    type="button"
                    class="address-card__action address-card__action--danger"
                    [disabled]="isLoading() || addresses().length === 1"
                    (click)="onDelete(addr)"
                    [attr.data-testid]="'address-delete-' + addr.id"
                  >
                    {{ 'addresses.actions.delete' | translate }}
                  </button>
                </div>
              </article>
            </div>

            <button
              type="button"
              class="address-book__add-cta"
              (click)="onStartCreate()"
              data-testid="address-add-cta"
            >
              + {{ 'addresses.actions.add' | translate }}
            </button>
          </ng-container>

          <ng-template #emptyState>
            <div class="address-book__empty" data-testid="address-book-empty">
              <p class="address-book__empty-body">
                {{ 'addresses.empty.body' | translate }}
              </p>
              <button
                type="button"
                class="address-book__add-cta address-book__add-cta--primary"
                (click)="onStartCreate()"
                data-testid="address-add-cta-empty"
              >
                {{ 'addresses.empty.cta' | translate }}
              </button>
            </div>
          </ng-template>
        </ng-container>

        <section
          *ngIf="mode() === 'create' || mode() === 'edit'"
          class="address-book__form-panel"
          [attr.aria-labelledby]="formHeadingId"
        >
          <h2 [id]="formHeadingId" class="address-book__form-title">
            {{ (mode() === 'create' ? 'addresses.form.titleCreate' : 'addresses.form.titleEdit') | translate }}
          </h2>
          <app-address-form
            [address]="editingAddress()"
            (saved)="onSaved($event)"
            (cancelled)="onCancelled()"
          ></app-address-form>
        </section>

        <p class="address-book__back-link">
          <a routerLink="/account" class="address-book__back-anchor">
            ← {{ 'addresses.backToAccount' | translate }}
          </a>
        </p>
      </div>
    </main>
  `,
  styleUrl: './address-book.scss',
})
export class AddressBookPageComponent implements OnInit {
  private readonly addressService = inject(AddressService);
  private readonly toast = inject(ToastService);

  protected readonly mode = signal<'list' | 'create' | 'edit'>('list');
  protected readonly editingAddress = signal<Address | null>(null);
  protected readonly formHeadingId = 'address-form-heading';

  protected readonly addresses = this.addressService.addresses;
  protected readonly isLoading = this.addressService.isLoading;

  ngOnInit(): void {
    void this.refresh();
  }

  private async refresh(): Promise<void> {
    try {
      await this.addressService.list();
    } catch {
      this.toast.error('addresses.errors.loadFailed');
    }
  }

  protected trackById(_idx: number, addr: Address): number {
    return addr.id;
  }

  /* -----------------------------------------------------------------
     Form mode transitions
     ----------------------------------------------------------------- */

  protected onStartCreate(): void {
    this.editingAddress.set(null);
    this.mode.set('create');
  }

  protected onEdit(addr: Address): void {
    this.editingAddress.set(addr);
    this.mode.set('edit');
  }

  protected onSaved(_addr: Address): void {
    /* AddressService.create/update already re-listed; just close the form. */
    this.mode.set('list');
    this.editingAddress.set(null);
    this.toast.success('addresses.toast.saved');
  }

  protected onCancelled(): void {
    this.mode.set('list');
    this.editingAddress.set(null);
  }

  /* -----------------------------------------------------------------
     Default + delete actions
     ----------------------------------------------------------------- */

  protected async onSetDefault(addr: Address): Promise<void> {
    try {
      await this.addressService.setDefault(addr.id, { shipping: true });
      this.toast.success('addresses.toast.defaultSet');
    } catch {
      this.toast.error('addresses.errors.unexpected');
    }
  }

  protected async onDelete(addr: Address): Promise<void> {
    /* Confirm via native browser confirm. A custom modal here would
       be the right Y.5 polish but is out of scope for Y.2. */
    if (typeof confirm !== 'undefined') {
      const ok = confirm(
        `Delete the address labelled "${addr.label ?? addr.recipient_name}"?`,
      );
      if (!ok) return;
    }
    try {
      await this.addressService.delete(addr.id);
      this.toast.success('addresses.toast.deleted');
    } catch {
      this.toast.error('addresses.errors.unexpected');
    }
  }
}
