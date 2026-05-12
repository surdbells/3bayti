import {
  AfterContentInit,
  ChangeDetectionStrategy,
  ChangeDetectorRef,
  Component,
  ContentChildren,
  EventEmitter,
  Input,
  OnDestroy,
  Output,
  QueryList,
  TemplateRef,
  ViewChild,
  forwardRef,
  inject,
} from '@angular/core';
import { CommonModule } from '@angular/common';
import { Subscription } from 'rxjs';

/**
 * Individual collapsible panel inside an <app-ax-accordion>.
 *
 *   <app-ax-accordion-item title="Billing details">
 *     ...body content...
 *   </app-ax-accordion-item>
 *
 * Controlled by its parent. Do NOT bind [expanded] yourself unless
 * you also bind the accordion's [multi]="true" — otherwise the parent
 * will fight your state.
 */
@Component({
  selector: 'app-ax-accordion-item',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ax-accordion-item" [class.ax-accordion-item-open]="expanded">
      <button
        type="button"
        class="ax-accordion-header"
        [id]="headerId"
        [attr.aria-expanded]="expanded"
        [attr.aria-controls]="panelId"
        [disabled]="disabled"
        (click)="toggle()"
      >
        <span *ngIf="icon" class="material-symbols-outlined" aria-hidden="true">{{ icon }}</span>
        <span class="ax-accordion-title">{{ title }}</span>
        <span *ngIf="badge != null" class="ax-tab-badge">{{ badge }}</span>
        <span class="ax-accordion-caret">
          <span class="material-symbols-outlined" aria-hidden="true">expand_more</span>
        </span>
      </button>
      <div
        *ngIf="expanded"
        role="region"
        [id]="panelId"
        [attr.aria-labelledby]="headerId"
        class="ax-accordion-body"
      >
        <ng-container *ngTemplateOutlet="bodyTpl"></ng-container>
      </div>
    </div>
    <ng-template #bodyTpl><ng-content></ng-content></ng-template>
  `,
})
export class AxAccordionItemComponent {
  @Input() title = '';
  @Input() icon?: string;
  @Input() badge?: number | string;
  @Input() disabled = false;

  /** Controlled by the parent accordion. Only mutate via toggle(). */
  expanded = false;
  private _id = Math.floor(Math.random() * 1e9);
  get headerId(): string { return `ax-acc-h-${this._id}`; }
  get panelId(): string { return `ax-acc-p-${this._id}`; }

  private readonly cdr = inject(ChangeDetectorRef);

  /** Emitted whenever the user toggles this item. Parent translates this
   *  into an expansion state update respecting multi/single mode. */
  readonly toggled = new EventEmitter<AxAccordionItemComponent>();

  toggle(): void {
    if (this.disabled) return;
    this.toggled.emit(this);
  }

  setExpanded(value: boolean): void {
    if (this.expanded === value) return;
    this.expanded = value;
    this.cdr.markForCheck();
  }
}

/**
 * Accordion container.
 *
 *   <app-ax-accordion [multi]="false">
 *     <app-ax-accordion-item title="General">...</app-ax-accordion-item>
 *     <app-ax-accordion-item title="Shipping">...</app-ax-accordion-item>
 *   </app-ax-accordion>
 *
 * Modes:
 *   [multi]="false"  (default)  — only one panel open at a time
 *   [multi]="true"              — any number open at once
 *
 * Set [defaultOpenIndex]="0" to open the first panel on init.
 */
@Component({
  selector: 'app-ax-accordion',
  standalone: true,
  imports: [CommonModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <div class="ax-accordion" [class.ax-accordion-flush]="flush">
      <ng-content></ng-content>
    </div>
  `,
})
export class AxAccordionComponent implements AfterContentInit, OnDestroy {
  @Input() multi = false;
  @Input() flush = false;
  @Input() defaultOpenIndex?: number | number[];

  @Output() openChange = new EventEmitter<number[]>();

  @ContentChildren(forwardRef(() => AxAccordionItemComponent))
  items!: QueryList<AxAccordionItemComponent>;

  private toggleSubs: Subscription[] = [];

  ngAfterContentInit(): void {
    this.applyDefaults();
    this.subscribe();
    this.items.changes.subscribe(() => {
      this.unsubscribe();
      this.subscribe();
    });
  }

  private applyDefaults(): void {
    const all = this.items.toArray();
    const defaults = this.defaultOpenIndex == null
      ? []
      : Array.isArray(this.defaultOpenIndex) ? this.defaultOpenIndex : [this.defaultOpenIndex];

    all.forEach((item, i) => item.setExpanded(defaults.includes(i)));
  }

  private subscribe(): void {
    this.toggleSubs = this.items.toArray().map(item =>
      item.toggled.subscribe(() => this.handleToggle(item)),
    );
  }

  private unsubscribe(): void {
    this.toggleSubs.forEach(s => s.unsubscribe());
    this.toggleSubs = [];
  }

  private handleToggle(source: AxAccordionItemComponent): void {
    const all = this.items.toArray();
    if (this.multi) {
      source.setExpanded(!source.expanded);
    } else {
      const willOpen = !source.expanded;
      all.forEach(item => item.setExpanded(item === source && willOpen));
    }
    const openIndices = all.map((item, i) => item.expanded ? i : -1).filter(i => i >= 0);
    this.openChange.emit(openIndices);
  }

  ngOnDestroy(): void {
    this.unsubscribe();
  }
}
