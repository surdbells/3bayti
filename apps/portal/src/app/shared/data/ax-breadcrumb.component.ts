import { ChangeDetectionStrategy, Component, Input } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';

export interface AxBreadcrumbItem {
  /** Text shown in the trail. */
  label: string;
  /** Route to navigate to. Omit on the last (current) item. */
  link?: string | any[];
  /** Optional Material Symbol icon name to prefix the label. */
  icon?: string;
}

/**
 * Breadcrumb trail with chevron separators.
 *
 *   <app-ax-breadcrumb [items]="[
 *     { label: 'Dashboard', link: '/backend', icon: 'home' },
 *     { label: 'Orders', link: '/processing' },
 *     { label: 'ABY-240098' }
 *   ]"></app-ax-breadcrumb>
 *
 * Last item (no `link`) is marked aria-current="page".
 */
@Component({
  selector: 'app-ax-breadcrumb',
  standalone: true,
  imports: [CommonModule, RouterLink],
  changeDetection: ChangeDetectionStrategy.OnPush,
  template: `
    <nav class="ax-breadcrumb" aria-label="Breadcrumb">
      <ng-container *ngFor="let item of items; let i = index; let last = last">
        <a
          *ngIf="item.link && !last; else currentItem"
          class="ax-breadcrumb-item"
          [routerLink]="item.link"
        >
          <span *ngIf="item.icon" class="material-symbols-outlined" aria-hidden="true">{{ item.icon }}</span>
          {{ item.label }}
        </a>
        <ng-template #currentItem>
          <span class="ax-breadcrumb-item" [attr.aria-current]="last ? 'page' : null">
            <span *ngIf="item.icon" class="material-symbols-outlined" aria-hidden="true">{{ item.icon }}</span>
            {{ item.label }}
          </span>
        </ng-template>

        <span *ngIf="!last" class="ax-breadcrumb-separator" aria-hidden="true">
          <span class="material-symbols-outlined">chevron_right</span>
        </span>
      </ng-container>
    </nav>
  `,
})
export class AxBreadcrumbComponent {
  @Input() items: AxBreadcrumbItem[] = [];
}
