import { Component, EventEmitter, HostListener, OnInit, Output } from '@angular/core';
import { GlobalComponent } from '../../global-component';
import { Router, RouterLink } from '@angular/router';
import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { CommonModule } from '@angular/common';
import { Notifications } from '../../class/notifications';
import { LanguageSwitcherComponent } from '../../language-switcher.component';

import { IconComponent } from '../../shared/icon/icon.component';
import { apiErrorMessage } from '../../shared/http/api-error';
@Component({
  selector: 'app-top',
  imports: [CommonModule, RouterLink, LanguageSwitcherComponent, IconComponent],
  standalone: true,
  templateUrl: './top.component.html',
  styleUrl: './top.component.css'
})
export class TopComponent implements OnInit {
  notifications?: Notifications[];

  @Output() menuToggle = new EventEmitter<void>();

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
    count: 0,
    notif_open: false,
    profile_open: false
  };

  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    avatar: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false
  };

  notification = { id: 0, token: '' };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.notification.id = this.user_session.id;
    this.notification.token = this.user_session.token;
    this.get_notifications();
  }

  error_notification(message: string) { this.toast.error(message); }
  success_notification(message: string) { this.toast.success(message); }

  toggle_notif(): void {
    this.ui_controls.notif_open = !this.ui_controls.notif_open;
    this.ui_controls.profile_open = false;
    if (this.ui_controls.notif_open) {
      this.get_notifications();
    }
  }

  toggle_profile(): void {
    this.ui_controls.profile_open = !this.ui_controls.profile_open;
    this.ui_controls.notif_open = false;
  }

  close_all(): void {
    this.ui_controls.notif_open = false;
    this.ui_controls.profile_open = false;
  }

  @HostListener('document:click')
  onDocumentClick(): void {
    this.close_all();
  }

  @HostListener('document:keydown.escape')
  onEscape(): void {
    this.close_all();
  }

  get_notifications() {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/notifications')
      .subscribe({
        next: (response: any) => {
          this.ui_controls.is_loading = false;
          this.notifications = response?.data ?? [];
          this.ui_controls.count = response?.meta?.unread ?? 0;
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
          this.ui_controls.is_loading = false;
        }
      });
  }

  mark_notifications() {
    this.ui_controls.is_loading = true;
    this.adapter.post_v3('POST /vendor/notifications/mark-read', {})
      .subscribe({
        next: () => {
          this.ui_controls.is_loading = false;
          this.get_notifications();
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification(apiErrorMessage(e, 'Unable to complete your request at this time.'));
          this.ui_controls.is_loading = false;
        }
      });
  }

  sign_out(): void {
    localStorage.clear();
    sessionStorage.clear();
    this.success_notification('User logged out successfully.');
    this.router.navigate(['/']).then(r => console.log(r));
  }
}
