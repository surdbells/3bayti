import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { NavigationHistoryService } from '../../../services/navigation-history.service';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
@Component({
  selector: 'app-store-reviews',
  standalone: true,
  imports: [AdminShellComponent, IconComponent],
  templateUrl: './store-reviews.component.html',
  styleUrl: './store-reviews.component.css',
})
export class StoreReviewsComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  store_name: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  get_data = { id: 0, token: '' };
  vendorId = 0;
  reviews: any[] = [];

  constructor(
    private router: Router,
    private navHistory: NavigationHistoryService,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.store_name = this.route.snapshot.queryParamMap.get('name');
    this.vendorId = Number(this.route.snapshot.queryParamMap.get('id'));
    if (this.vendorId) this.get_post();
  }

  goBack() {
    this.navHistory.back('/stores');
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_post() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendors/:vendorId/reviews', {
      params: { vendorId: String(this.vendorId) },
      query: { limit: 50, offset: 0 },
    }).subscribe({
      next: (response: any) => {
        const d = response?.data ?? response;
        this.reviews = Array.isArray(d) ? d : (d?.items ?? d?.reviews ?? []);
        this.ui_controls.no_data = this.reviews.length === 0;
        this.ui_controls.is_loading = false;
      },
      error: () => { this.ui_controls.is_loading = false; },
    });
  }
}
