import { Component, OnInit } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { ProductFormComponent } from '../../shared/product-form/product-form.component';

/**
 * Vendor "create product" page, a thin shell wrapper around the shared
 * <app-product-form>. (Admins reach product creation through the admin
 * product screen, which uses the same form in adminMode.)
 */
@Component({
  selector: 'app-create-product',
  standalone: true,
  imports: [CommonModule, VendorShellComponent, AdminShellComponent, ProductFormComponent],
  template: `
    <app-vendor-shell *ngIf="!isAdmin; else adminShell">
      <app-product-form mode="create"></app-product-form>
    </app-vendor-shell>
    <ng-template #adminShell>
      <app-admin-shell>
        <app-product-form mode="create" [adminMode]="true" [vendorId]="vendorId"></app-product-form>
      </app-admin-shell>
    </ng-template>
  `,
})
export class CreateProductComponent implements OnInit {
  isAdmin = false;
  /** When opened from a store's product page (?vendor_id=), pre-select that store. */
  vendorId = 0;

  constructor(private route: ActivatedRoute) {}

  ngOnInit(): void {
    const s = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.isAdmin = !!s?.is_admin && !s?.is_vendor;
    this.vendorId = Number(this.route.snapshot.queryParamMap.get('vendor_id')) || 0;
  }
}
