import { Component, OnInit } from '@angular/core';
import { CrudService } from '../../services/crud.service';
import { Router, RouterLink } from '@angular/router';
import { HotToastService } from '@ngneat/hot-toast';
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
    this.crudService.post_request(this.login, GlobalComponent.UserLogin).subscribe({
      next: (response) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.user_session = response.data;
          this.loading = false;
          this.success_notification(response.message);
          if (this.user_session.is_active) {
            this.user_session_string = GlobalComponent.encodeBase64(this.user_session);
            sessionStorage.setItem('SESSION', this.user_session_string);
            if (this.user_session.is_admin) {
              this.router.navigate(['/', 'backend']).then(r => console.log(r));
              return;
            }
            if (this.user_session.is_finance) {
              this.router.navigate(['/', 'backend']).then(r => console.log(r));
              return;
            }
            if (this.user_session.is_support) {
              this.router.navigate(['/', 'backend']).then(r => console.log(r));
              return;
            }
            if (this.user_session._sub_admin) {
              this.router.navigate(['/', 'backend']).then(r => console.log(r));
              return;
            }
            if (this.user_session.is_vendor) {
              this.router.navigate(['/', 'account']).then(r => console.log(r));
              return;
            }
          }
        }
        if (response.response_code == 200 && response.status === 'failed') {
          this.loading = false;
          this.error_notification(response.message);
        }
        if (response.response_code == 400 && response.status === 'failed') {
          this.loading = false;
          this.error_notification(response.message);
        }
      },
      error: (e) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.loading = false;
      },
      complete: () => {
        console.info('complete');
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
