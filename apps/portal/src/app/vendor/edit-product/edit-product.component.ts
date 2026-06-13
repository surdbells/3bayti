import { Component, OnInit } from '@angular/core';
import { ActivatedRoute } from '@angular/router';
import { CommonModule } from '@angular/common';
import { GlobalComponent } from '../../global-component';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
import { ProductFormComponent } from '../../shared/product-form/product-form.component';

/**
 * Vendor "edit product" page — a thin shell wrapper around the shared
 * <app-product-form> in edit mode. Reads the product id from ?id.
 */
@Component({
  selector: 'app-edit-product',
  standalone: true,
  imports: [CommonModule, VendorShellComponent, AdminShellComponent, ProductFormComponent],
  template: `
    <app-vendor-shell *ngIf="!isAdmin; else adminShell">
      <app-product-form mode="edit" [productId]="productId"></app-product-form>
    </app-vendor-shell>
    <ng-template #adminShell>
      <app-admin-shell>
        <app-product-form mode="edit" [adminMode]="true" [productId]="productId"></app-product-form>
      </app-admin-shell>
    </ng-template>
  `,
})
export class EditProductComponent implements OnInit {
  isAdmin = false;
  productId = 0;
  constructor(private route: ActivatedRoute) {}
  ngOnInit(): void {
    const s = GlobalComponent.decodeBase64(sessionStorage.getItem('SESSION') ?? '');
    this.isAdmin = !!s?.is_admin && !s?.is_vendor;
    this.productId = Number(this.route.snapshot.queryParamMap.get('id')) || 0;
  }
}
