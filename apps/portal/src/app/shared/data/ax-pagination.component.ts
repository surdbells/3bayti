import { ChangeDetectionStrategy, Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';

type PageItem = number | 'ellipsis';

/**
 * Fully-controlled pagination. Emits (pageIndexChange) and
 * (pageSizeChange); the parent is expected to feed those back via
 * [pageIndex] and [pageSize].
 *
 *   <app-ax-pagination
 *     [totalCount]="orders.length"
 *     [pageSize]="20"
 *     [pageIndex]="currentPage"
 *     (pageIndexChange)="currentPage = $event">
 *   </app-ax-pagination>
 */
@Component({
  selector: 'app-ax-pagination',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="ax-pagination" aria-label="Pagination">
      <span class="ax-pagination-summary">
        {{ showingFrom }}–{{ showingTo }} of {{ totalCount | number }}
      </span>

      <div class="ax-pagination-controls">
        <button
          type="button"
          class="ax-pagination-btn"
          [disabled]="pageIndex === 0"
          aria-label="Previous page"
          (click)="goTo(pageIndex - 1)"
        >
          <span class="material-symbols-outlined" aria-hidden="true">chevron_left</span>
        </button>

        <ng-container *ngFor="let item of pageItems; let i = index">
          <button
            *ngIf="item !== 'ellipsis'"
            type="button"
            class="ax-pagination-btn"
            [class.ax-pagination-btn-active]="item === pageIndex"
            [attr.aria-current]="item === pageIndex ? 'page' : null"
            [attr.aria-label]="'Go to page ' + (item + 1)"
            (click)="goTo(+item)"
          >
            {{ +item + 1 }}
          </button>
          <span *ngIf="item === 'ellipsis'" class="ax-pagination-ellipsis" aria-hidden="true">…</span>
        </ng-container>

        <button
          type="button"
          class="ax-pagination-btn"
          [disabled]="pageIndex >= totalPages - 1"
          aria-label="Next page"
          (click)="goTo(pageIndex + 1)"
        >
          <span class="material-symbols-outlined" aria-hidden="true">chevron_right</span>
        </button>
      </div>

      <label *ngIf="pageSizeOptions?.length" class="ax-pagination-size">
        Rows per page:
        <select [value]="pageSize" (change)="onSizeChange($event)" aria-label="Rows per page">
          <option *ngFor="let s of pageSizeOptions" [value]="s">{{ s }}</option>
        </select>
      </label>
    </nav>
  `,
})
export class AxPaginationComponent {
  @Input() totalCount = 0;
  @Input() pageSize = 10;
  @Input() pageIndex = 0;

  /** Optional selector for page sizes. Leave empty to hide the dropdown. */
  @Input() pageSizeOptions: number[] = [10, 25, 50, 100];

  /** Max number of page buttons to show before ellipsing. Default 7. */
  @Input() maxButtons = 7;

  @Output() pageIndexChange = new EventEmitter<number>();
  @Output() pageSizeChange = new EventEmitter<number>();

  get totalPages(): number {
    return Math.max(1, Math.ceil(this.totalCount / Math.max(1, this.pageSize)));
  }

  get showingFrom(): number {
    if (!this.totalCount) return 0;
    return this.pageIndex * this.pageSize + 1;
  }

  get showingTo(): number {
    return Math.min(this.totalCount, (this.pageIndex + 1) * this.pageSize);
  }

  get pageItems(): PageItem[] {
    const total = this.totalPages;
    const current = this.pageIndex;
    const max = Math.max(5, this.maxButtons);

    if (total <= max) {
      return Array.from({ length: total }, (_, i) => i);
    }

    const items: PageItem[] = [];
    const siblings = 1; // pages either side of current
    const leftSibling = Math.max(current - siblings, 1);
    const rightSibling = Math.min(current + siblings, total - 2);

    const showLeftEllipsis = leftSibling > 2;
    const showRightEllipsis = rightSibling < total - 3;

    items.push(0); // first

    if (!showLeftEllipsis && showRightEllipsis) {
      // first few pages then ellipsis then last
      const leftCount = Math.min(max - 2, total - 2);
      for (let i = 1; i <= leftCount; i++) items.push(i);
      items.push('ellipsis');
      items.push(total - 1);
    } else if (showLeftEllipsis && !showRightEllipsis) {
      // first then ellipsis then last few
      items.push('ellipsis');
      const rightCount = Math.min(max - 2, total - 2);
      for (let i = total - 1 - rightCount; i < total - 1; i++) items.push(i);
      items.push(total - 1);
    } else if (showLeftEllipsis && showRightEllipsis) {
      items.push('ellipsis');
      for (let i = leftSibling; i <= rightSibling; i++) items.push(i);
      items.push('ellipsis');
      items.push(total - 1);
    } else {
      for (let i = 1; i < total - 1; i++) items.push(i);
      items.push(total - 1);
    }

    return items;
  }

  goTo(index: number): void {
    if (index < 0 || index >= this.totalPages || index === this.pageIndex) return;
    this.pageIndexChange.emit(index);
  }

  onSizeChange(event: Event): void {
    const newSize = Number((event.target as HTMLSelectElement).value);
    this.pageSizeChange.emit(newSize);
    // When size changes, reset page to 0 so the pointer doesn't land on
    // an empty page. Parent receives pageSizeChange and is expected to
    // also update pageIndex; we emit it defensively.
    this.pageIndexChange.emit(0);
  }
}
