import { Component, OnInit } from '@angular/core';
import { NgIf } from '@angular/common';
import { Router } from '@angular/router';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { apiErrorMessage } from '../../../shared/http/api-error';
import { FormsModule } from '@angular/forms';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';

/**
 * Create an admin-curated storefront collection (name + optional
 * description). On success the admin lands on the edit page for the new
 * collection to curate its products.
 */
@Component({
  selector: 'app-create-collection',
  standalone: true,
  imports: [AdminShellComponent, NgIf, FormsModule, IconComponent],
  templateUrl: './create-collection.component.html',
  styleUrl: './create-collection.component.css',
})
export class CreateCollectionComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  create = { collection: '', description: '' };

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
  }

  goBack() {
    this.navHistory.back('/admin/collections');
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  createCollection() {
    if (this.ui_controls.is_loading) return;
    const name = this.create.collection.trim();
    if (name.length === 0) {
      this.error_notification('Collection name cannot be empty');
      return;
    }
    const body: { name: string; description?: string } = { name };
    const description = this.create.description.trim();
    if (description) body.description = description;

    this.ui_controls.is_loading = true;
    this.adapter.post_v3('POST /admin/collections', body).subscribe({
      next: (response: any) => {
        this.ui_controls.is_loading = false;
        const created = response?.data ?? response;
        const id = Number(created?.id);
        if (Number.isFinite(id) && id > 0) {
          this.success_notification('Collection created — now add products.');
          // replaceUrl: "Back" from the edit page returns to the list, not
          // to this (now finished) create form.
          this.router.navigate(['/admin/collections/edit'], { queryParams: { id }, replaceUrl: true });
        } else {
          // Created, but the response carried no id to deep-link to.
          this.success_notification('Collection created.');
          this.router.navigate(['/admin/collections']);
        }
      },
      error: (e: any) => {
        this.error_notification(apiErrorMessage(e, 'Unable to create the collection.'));
        this.ui_controls.is_loading = false;
      },
    });
  }
}
