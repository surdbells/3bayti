import { Component, EventEmitter, HostListener, OnInit, Output } from '@angular/core';
import { GlobalComponent } from '../../global-component';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { CommonModule } from '@angular/common';
import { Notifications } from '../../class/notifications';
import { LanguageSwitcherComponent } from '../../language-switcher.component';

@Component({
  selector: 'app-top',
  imports: [CommonModule, RouterLink, LanguageSwitcherComponent],
  standalone: true,
  templateUrl: './top.component.html',
  styleUrl: './top.component.css'
})
export class TopComponent implements OnInit {
  notifications?: Notifications[];

  @Output() menuToggle = new EventEmitter<void>();

  constructor(
    private router: Router,
    private crudService: CrudService,
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
    this.crudService.post_request(this.notification, GlobalComponent.getNotifications)
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.ui_controls.is_loading = false;
            this.ui_controls.count = response.message;
            this.notifications = response.data;
          } else {
            this.ui_controls.is_loading = false;
          }
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification('Unable to complete your request at this time.');
          this.ui_controls.is_loading = false;
        }
      });
  }

  mark_notifications() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.notification, GlobalComponent.markNotifications)
      .subscribe({
        next: (response: any) => {
          if (response.response_code === 200 && response.status === 'success') {
            this.ui_controls.is_loading = false;
            this.success_notification(response.message);
            this.get_notifications();
          } else {
            this.error_notification(response.message);
            this.ui_controls.is_loading = false;
          }
        },
        error: (e: any) => {
          console.error(e);
          this.error_notification('Unable to complete your request at this time.');
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
