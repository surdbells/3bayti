import { Component, OnInit } from '@angular/core';
import { CrudService } from '../../services/crud.service';
import { PortalAuthService } from '../../services/portal-auth.service';
import { Router, RouterLink } from '@angular/router';
import { HotToastService } from '../../shared/toast/toast.service';
import { CookieService } from 'ngx-cookie-service';
import { GlobalComponent } from '../../global-component';
import { FormsModule } from '@angular/forms';
import { CommonModule } from '@angular/common';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';

@Component({
  selector: 'app-login',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterLink,
    LanguageSwitcherComponent,
    TranslatePipe,
  ],
  templateUrl: './login.component.html',
  styleUrl: './login.component.css',
})
export class LoginComponent implements OnInit {
  constructor(
    private crudService: CrudService,
    private authService: PortalAuthService,
    private router: Router,
    private cookieService: CookieService,
    private toast: HotToastService,
  ) {}

  user_session_string = '';
  fieldTextType = false;
  loading = false;

  login = {
    email: '',
    password: '',
  };

  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    avatar: '',
    id_front: '',
    id_back: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_support: false,
    is_finance: false,
    _sub_admin: false,
    is_vendor: false,
    is_customer: false,
  };

  ngOnInit(): void {
    sessionStorage.clear();
  }

  user_login() {
    if (this.login.email.length === 0) {
      this.error_notification('Email address is required');
      return;
    }
    if (this.login.password.length === 0) {
      this.error_notification('Password is required');
      return;
    }
    if (!GlobalComponent.validateEmail(this.login.email)) {
      this.error_notification('Invalid email format provided');
      return;
    }
    this.loading = true;
    // M3.3.0-C — flipped to v3 auth via PortalAuthService.
    // Response is mapped to the legacy SESSION shape so all downstream
    // components (role guards, header nav, etc.) work without changes.
    this.authService.login(this.login.email, this.login.password).subscribe({
      next: (response) => {
        this.loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.user_session = response.data;
          this.success_notification(response.message);
          // SESSION already written by PortalAuthService — just navigate.
          if (this.user_session.is_admin || this.user_session.is_finance ||
              this.user_session.is_support || this.user_session._sub_admin) {
            this.router.navigate(['/', 'backend']);
            return;
          }
          if (this.user_session.is_vendor) {
            this.router.navigate(['/', 'account']);
            return;
          }
          // Fallback — role not recognised for portal access
          this.error_notification('Your account does not have portal access.');
          sessionStorage.clear();
          return;
        }
        // Explicit failure response from the service
        this.error_notification(response.message ?? 'Login failed. Please try again.');
      },
      error: (e) => {
        this.loading = false;
        const msg = e?.error?.message ?? e?.message ?? 'Invalid credentials.';
        this.error_notification(msg);
      },
      complete: () => {
        console.info('login complete');
      },
    });
  }

  toggleFieldTextType() {
    this.fieldTextType = !this.fieldTextType;
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }
}
