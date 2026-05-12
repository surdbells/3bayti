import {Component, OnInit} from '@angular/core';
import {CrudService} from '../../services/crud.service';
import {Router, RouterLink} from '@angular/router';
import {CookieService} from 'ngx-cookie-service';
import {HotToastService} from '@ngneat/hot-toast';
import {GlobalComponent} from '../../global-component';
import {FormsModule} from '@angular/forms';
import {CommonModule} from '@angular/common';
import {TranslatePipe} from '../../translate.pipe';
import {LanguageSwitcherComponent} from '../../language-switcher.component';

@Component({
  selector: 'app-reset',
  imports: [
    FormsModule,
    CommonModule,
    RouterLink,
    TranslatePipe,
    LanguageSwitcherComponent
  ],
  standalone: true,
  templateUrl: './reset.component.html',
  styleUrl: './reset.component.css'
})
export class ResetComponent implements OnInit{
  constructor(
    private crudService: CrudService,
    private router: Router,
    private cookieService: CookieService,
    private toast: HotToastService
  ) {}
  fieldTextType: boolean = false;
  ui_controls = {
    loading: false,
    confirmed: false,
    validated: false
  };
  ngOnInit(): void {

  }
  reset = {
    email: ""
  };
  confirm = {
    otp: "",
    input_otp: "",
    expires_at: 0,
    email: ""
  };
  r_password = {
    email: "",
    password: "",
    confirm_password: ""
  };
  r_response = {
    otp: "",
    expires_at: 0
  };
  user_confirm() {
    if (this.reset.email.length === 0) {
      this.error_notification("Email address is required");
      return;
    }
    if (!GlobalComponent.validateEmail(this.reset.email)) {
      this.error_notification("Invalid email format provided");
      return;
    }
    this.ui_controls.loading = true;
    this.crudService.post_request(this.reset, GlobalComponent.UserConfirm)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.ui_controls.confirmed = true;
            this.success_notification(response.message);
            this.r_response = response.data;
            this.confirm.otp = this.r_response.otp;
            this.confirm.expires_at = this.r_response.expires_at;
            this.confirm.email = this.reset.email;
          }
          if (response.response_code == 200 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Unable to complete your request at this time.");
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  user_validate() {
    if (this.confirm.input_otp.length === 0) {
      this.error_notification("OTP is required");
      return;
    }
    this.ui_controls.loading = true;
    this.crudService.post_request(this.confirm, GlobalComponent.UserValidate)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.ui_controls.validated = true;
            this.r_password.email = this.reset.email;
            this.success_notification(response.message);
          }
          if (response.response_code == 200 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Unable to complete your request at this time.");
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  reset_password() {
    if (this.r_password.password.length === 0) {
      this.error_notification("Password is required");
      return;
    }
    if (this.r_password.confirm_password.length === 0) {
      this.error_notification("Password does not match");
      return;
    }
    if (this.r_password.password != this.r_password.confirm_password) {
      this.error_notification("Password does not match");
      return;
    }
    this.ui_controls.loading = true;
    this.crudService.post_request(this.r_password, GlobalComponent.UserReset)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.success_notification(response.message);
            this.router.navigate(['/']).then(r => console.log(r));
          }
          if (response.response_code == 200 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
          if (response.response_code == 400 && response.status === "failed") {
            this.ui_controls.loading = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification("Unable to complete your request at this time.");
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
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
