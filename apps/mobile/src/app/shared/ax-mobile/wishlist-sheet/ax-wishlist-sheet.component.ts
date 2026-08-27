import { CommonModule } from '@angular/common';
import { Component, EventEmitter, Input, Output } from '@angular/core';
import { FormsModule } from '@angular/forms';

import { TranslatePipe } from '../../../translate.pipe';
import { AxIconComponent } from '../icon';
import { AxLoaderComponent } from '../loader';
import { AxBottomSheetComponent } from '../bottom-sheet';

/** Minimal label shape the sheet renders, a subset of WishlistLabel. */
export interface WishlistSheetLabel {
  id: number;
  name: string;
  count?: number;
}

/**
 * Shared "save product to a wishlist label" bottom sheet.
 *
 * Previously this markup + list/loading state was copy-pasted into every page
 * that offers "add to closet" (best-sellers, category, search, product, …).
 * Centralising it here means the inline "Create new label" affordance, so a
 * user with no lists yet can make one without leaving the sheet, lives in one
 * place instead of nine.
 *
 * The component is presentational: the parent owns the WishlistService calls.
 *  - picking an existing label emits (select) with the label id
 *  - creating a label emits (createLabel) with the trimmed name; the parent
 *    performs POST /me/wishlist/labels, toggles [creating] while in flight,
 *    then typically adds the product to the new label and closes the sheet
 *    (closing resets the inline form via the isOpen setter).
 */
@Component({
  selector: 'ax-wishlist-sheet',
  standalone: true,
  imports: [CommonModule, FormsModule, TranslatePipe, AxIconComponent, AxLoaderComponent, AxBottomSheetComponent],
  templateUrl: './ax-wishlist-sheet.component.html',
  styleUrl: './ax-wishlist-sheet.component.scss',
})
export class AxWishlistSheetComponent {
  /** Open state. Closing always clears the inline create form. */
  @Input() set isOpen(v: boolean) {
    this._isOpen = v;
    if (!v) {
      this.resetCreate();
    }
  }
  get isOpen(): boolean {
    return this._isOpen;
  }
  private _isOpen = false;

  /** Product name shown in the sheet title ("Save <name> to"). */
  @Input() productName = '';

  /** Existing labels to pick from. */
  @Input() labels: WishlistSheetLabel[] = [];

  /** Labels still loading, shows a spinner instead of the list. */
  @Input() loading = false;

  /** Parent toggles this true while POST /me/wishlist/labels is in flight. */
  @Input() creating = false;

  @Output() dismiss = new EventEmitter<void>();
  @Output() select = new EventEmitter<number>();
  @Output() createLabel = new EventEmitter<string>();

  showCreateForm = false;
  newLabelName = '';

  onDismiss(): void {
    this.dismiss.emit();
  }

  choose(labelId: number): void {
    this.select.emit(labelId);
  }

  openCreateForm(): void {
    this.showCreateForm = true;
  }

  submitCreate(): void {
    const name = this.newLabelName.trim();
    if (name.length === 0 || this.creating) {
      return;
    }
    this.createLabel.emit(name);
  }

  cancelCreate(): void {
    this.resetCreate();
  }

  private resetCreate(): void {
    this.showCreateForm = false;
    this.newLabelName = '';
  }
}
