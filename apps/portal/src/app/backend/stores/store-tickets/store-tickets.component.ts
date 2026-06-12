import { Component, OnInit } from '@angular/core';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-store-tickets',
  standalone: true,
  imports: [AdminShellComponent, ],
  templateUrl: './store-tickets.component.html',
  styleUrl: './store-tickets.component.css',
})
export class StoreTicketsComponent implements OnInit {
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
  tickets: any[] = [];

  constructor(
    private router: Router,
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.store_name = this.route.snapshot.queryParamMap.get('name');
  }

  goBack() {
    this.router.navigate(['/stores']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_post() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /admin/tickets', { query: { limit: 50, offset: 0 } }).subscribe({
      next: (response: any) => {
        if (response?.data) {
          this.tickets = Array.isArray(response.data) ? response.data : response.data?.items ?? [];
          this.ui_controls.is_loading = false;
        }
      },
    });
  }
}
