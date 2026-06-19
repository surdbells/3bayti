import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { Router } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { GlobalComponent } from '../../global-component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';

import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-gift-cards-admin',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
  template: `
<app-admin-shell>
  <div class="ax-container">
    <header class="ax-page-header">
      <div class="ax-page-header-content">
        <button type="button" (click)="goBack()" class="ax-btn ax-btn-ghost ax-btn-sm ax-mb-2" style="align-self:flex-start">
          <app-icon name="arrow_back"></app-icon> Back to dashboard
        </button>
        <span class="ax-page-header-eyebrow">Platform</span>
        <h1 class="ax-page-title">Gift Cards</h1>
        <p *ngIf="total > 0" class="ax-page-subtitle">{{ total }} gift card themes available</p>
      </div>
      <div class="ax-flex ax-gap-2 ax-items-center">
        <button type="button" class="ax-btn ax-btn-outline ax-btn-sm" (click)="load()" [disabled]="loading">
          <app-icon name="refresh"></app-icon> Refresh
        </button>
      </div>
    </header>

    <div *ngIf="loading" class="ax-flex ax-justify-center ax-py-8">
      <span class="ax-spinner ax-spinner-lg"></span>
    </div>

    <section *ngIf="!loading && themes.length > 0" class="ax-card ax-p-0">
      <div class="ax-table-wrapper">
        <table class="ax-table ax-table-hover">
          <thead>
            <tr>
              <th>Theme</th>
              <th>Arabic Label</th>
              <th>Pattern</th>
              <th>Primary Colour</th>
              <th>Accent Colour</th>
              <th>Photo</th>
            </tr>
          </thead>
          <tbody>
            <tr *ngFor="let t of themes">
              <td><span class="ax-font-medium ax-text-primary">{{ t.label }}</span></td>
              <td><span class="ax-text-sm ax-text-secondary" dir="rtl">{{ t.arabic_label }}</span></td>
              <td><span class="ax-badge ax-badge-neutral">{{ t.pattern }}</span></td>
              <td>
                <span class="ax-flex ax-items-center ax-gap-2">
                  <span [style.background]="t.primary_color" style="width:1rem;height:1rem;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.1)"></span>
                  <span class="ax-text-sm ax-text-secondary">{{ t.primary_color }}</span>
                </span>
              </td>
              <td>
                <span class="ax-flex ax-items-center ax-gap-2">
                  <span [style.background]="t.accent_color" style="width:1rem;height:1rem;border-radius:3px;display:inline-block;border:1px solid rgba(0,0,0,.1)"></span>
                  <span class="ax-text-sm ax-text-secondary">{{ t.accent_color }}</span>
                </span>
              </td>
              <td>
                <span *ngIf="t.supports_photo" class="ax-badge ax-badge-success">Yes</span>
                <span *ngIf="!t.supports_photo" class="ax-badge ax-badge-neutral">No</span>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <section *ngIf="!loading && themes.length === 0" class="ax-page-section">
      <div class="ax-card ax-p-8 ax-text-center">
        <app-icon name="card_giftcard" style="font-size:3rem;color:var(--ax-color-text-tertiary)"></app-icon>
        <h3 class="ax-h4 ax-m-0 ax-mt-3">No gift card themes</h3>
      </div>
    </section>
  </div>
</app-admin-shell>
  `,
})
export class GiftCardsAdminComponent implements OnInit {
  themes: any[] = [];
  total = 0;
  loading = false;

  user_session: any = {};

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    const raw = sessionStorage.getItem('SESSION') ?? '';
    this.user_session = GlobalComponent.decodeBase64(raw);
    this.load();
  }

  goBack() { this.router.navigate(['/backend']); }

  load() {
    this.loading = true;
    this.adapter.get_v3('GET /gift-cards/themes').subscribe({
      next: (res: any) => {
        // Tolerate {data:{themes}}, {data:[...]}, or {themes} envelopes.
        const giftData = res?.data ?? res;
        this.themes = Array.isArray(giftData) ? giftData : (giftData?.themes ?? res?.themes ?? []);
        this.total = this.themes.length;
        this.loading = false;
      },
      error: () => { this.loading = false; this.toast.error('Failed to load gift card themes.'); },
    });
  }
}
