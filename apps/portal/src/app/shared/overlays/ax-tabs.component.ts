import {
  AfterContentInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ContentChildren,
  EventEmitter,
  Input,
  Output,
  QueryList,
  TemplateRef,
  ViewChild,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';

/**
 * Individual tab panel. Declared as a child of <app-ax-tabs>.
 *
 *   <app-ax-tab label="General">...content...</app-ax-tab>
 *
 * A tab's content is projected via its template, so lazy content is
 * only rendered when the tab is active.
 */
@Component({
  selector: 'app-ax-tab',
  standalone: true,
  template: `<ng-template><ng-content></ng-content></ng-template>`,
})
export class AxTabComponent {
  @Input() label = '';
  /** Icon name from Material Symbols Outlined. Optional. */
  @Input() icon?: string;
  /** Numeric badge displayed after the label. */
  @Input() badge?: number | string;
  @Input() disabled = false;
  /** Arbitrary id you can use to select this tab programmatically. */
  @Input() tabId?: string;

  @ViewChild(TemplateRef, { static: true }) template!: TemplateRef<unknown>;
}

/**
 * Tab switcher.
 *
 *   <app-ax-tabs [(selectedIndex)]="idx">
 *     <app-ax-tab label="Overview" icon="analytics">...</app-ax-tab>
 *     <app-ax-tab label="Orders" [badge]="12">...</app-ax-tab>
 *   </app-ax-tabs>
 *
 * Arrow-key keyboard navigation. ARIA tablist pattern.
 */
@Component({
  selector: 'app-ax-tabs',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ax-tabs" [attr.aria-label]="ariaLabel">
      <div class="ax-tabs-list" role="tablist">
        <button
          *ngFor="let tab of tabs; let i = index"
          #tabButton
          type="button"
          role="tab"
          class="ax-tab"
          [id]="tabId(i)"
          [attr.aria-selected]="i === selectedIndex"
          [attr.aria-controls]="panelId(i)"
          [attr.tabindex]="i === selectedIndex ? 0 : -1"
          [disabled]="tab.disabled"
          (click)="selectTab(i)"
          (keydown)="onKeydown($event, i)"
        >
          <span *ngIf="tab.icon" class="material-symbols-outlined" aria-hidden="true">{{ tab.icon }}</span>
          {{ tab.label }}
          <span *ngIf="tab.badge != null" class="ax-tab-badge">{{ tab.badge }}</span>
        </button>
      </div>
      <div
        *ngFor="let tab of tabs; let i = index"
        role="tabpanel"
        class="ax-tab-panel"
        [id]="panelId(i)"
        [attr.aria-labelledby]="tabId(i)"
        [hidden]="i !== selectedIndex"
      >
        <ng-container *ngIf="i === selectedIndex || !lazy">
          <ng-container *ngTemplateOutlet="tab.template"></ng-container>
        </ng-container>
      </div>
    </div>
  `,
})
export class AxTabsComponent implements AfterContentInit {
  @Input() selectedIndex = 0;
  @Output() selectedIndexChange = new EventEmitter<number>();
  /** Accessible label for the tab group. */
  @Input() ariaLabel?: string;
  /** Only render the active tab's content. Default true. */
  @Input() lazy = true;

  @ContentChildren(AxTabComponent) tabList!: QueryList<AxTabComponent>;
  tabs: AxTabComponent[] = [];

  private readonly cdr = inject(ChangeDetectorRef);
  private readonly componentId = Math.floor(Math.random() * 1e9);

  ngAfterContentInit(): void {
    this.tabs = this.tabList.toArray();
    this.tabList.changes.subscribe(() => {
      this.tabs = this.tabList.toArray();
      if (this.selectedIndex >= this.tabs.length) {
        this.selectedIndex = Math.max(0, this.tabs.length - 1);
      }
      this.cdr.markForCheck();
    });
  }

  selectTab(index: number): void {
    if (!this.tabs[index] || this.tabs[index].disabled) return;
    if (this.selectedIndex === index) return;
    this.selectedIndex = index;
    this.selectedIndexChange.emit(index);
    this.cdr.markForCheck();
  }

  onKeydown(event: KeyboardEvent, index: number): void {
    const total = this.tabs.length;
    if (!total) return;
    let next = -1;
    switch (event.key) {
      case 'ArrowRight': next = (index + 1) % total; break;
      case 'ArrowLeft':  next = (index - 1 + total) % total; break;
      case 'Home':       next = 0; break;
      case 'End':        next = total - 1; break;
      case 'Enter':
      case ' ':
        event.preventDefault();
        this.selectTab(index);
        return;
      default:
        return;
    }
    if (next < 0) return;
    event.preventDefault();
    // Skip disabled tabs
    let attempts = 0;
    while (this.tabs[next]?.disabled && attempts < total) {
      next = event.key === 'ArrowLeft' ? (next - 1 + total) % total : (next + 1) % total;
      attempts++;
    }
    this.selectTab(next);

    // Move focus to the newly-selected tab button
    setTimeout(() => {
      const buttons = (document.querySelectorAll(`#${this.tabId(next)}`));
      (buttons[0] as HTMLElement | undefined)?.focus();
    }, 0);
  }

  tabId(i: number): string { return `ax-tab-${this.componentId}-${i}`; }
  panelId(i: number): string { return `ax-tab-panel-${this.componentId}-${i}`; }
}
