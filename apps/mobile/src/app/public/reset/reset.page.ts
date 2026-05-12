import {Component, OnDestroy, OnInit} from '@angular/core';
import {
  IonContent,
  IonCol,
  IonGrid,
  IonRow,
  IonText,
  Platform,
  IonButton,
} from '@ionic/angular/standalone';
import { ConnectionService } from '../../service/connection.service';
import { Subscription } from 'rxjs';

import { FormsModule } from '@angular/forms';
import {Router} from "@angular/router";
import {NetworkService} from "../../service/network.service";
import {AxNotificationService} from '../../shared/ax-mobile/notification';
import { I18nService } from '../../i18n.service';
import {TranslatePipe} from "../../translate.pipe";
import {GlobalComponent} from "../../global-component";
import { AxLoaderComponent } from '../../shared/ax-mobile/loader';
import { AxTextFieldComponent } from '../../shared/ax-mobile/text-field';
@Component({
  selector: 'app-reset',
  templateUrl: './reset.page.html',
  styleUrls: ['./reset.page.scss'],
  standalone: true,
  imports: [
    IonContent,
    IonGrid,
    IonCol,
    IonRow,
    FormsModule,
    IonText,
    IonButton,
    TranslatePipe,
    AxLoaderComponent,
    AxTextFieldComponent,
  ]
})
export class ResetPage implements OnInit, OnDestroy {
  isOnline = true;
  private sub: Subscription;
  showResendToken: boolean = false; // Initially hide the button
  constructor(
    private net: ConnectionService,
    private platform: Platform,
    private router: Router,
    private networkService: NetworkService,
    private toast: AxNotificationService,
    private i18n: I18nService,
  ) {
    this.net.setReachabilityCheck(true);
    this.sub = this.net.online$.subscribe(v => this.isOnline = v);
  }
  ngOnDestroy(): void {
    this.sub?.unsubscribe();
  }
  ngOnInit() {
    this.getAuthToken();
  }
  ui_controls = {
    loading: false,
    registered: false,
    otpValidated: false,
    otpSent: false,
    termsArabic: true,
    termsEnglish: false
  };

  reset_p = {
    phone: "",
    password: "",
    confirm_password: ""
  };

  smsToken = {
    response_code: 0,
    status: "",
    message: "",
    data: ""
  };

  sendOTP = {
    token: "",
    number: ""
  };
  validateOtp = {
    token: "",
    verificationId: "",
    code: ""
  };

  r_response = {
    responseCode: 0,
    message: "",
    data: {
      verificationId: "",
      mobileNumber: "",
      responseCode: "",
      errorMessage: "",
      timeout: "",
      smCLI: "",
      transactionId: ""
    }
  }
  v_response = {
    responseCode: 0,
    message: "",
    data: {
      verificationId: "",
      mobileNumber: "",
      responseCode: "",
      errorMessage: "",
      verificationStatus: "",
      authToken: "",
      transactionId: ""
    }
  }
  reset_password() {
    if (this.reset_p.password.length === 0) {
      this.error_notification(this.i18n.t('text_password_required'));
      return;
    }
    if (this.reset_p.confirm_password.length === 0) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }
    if (this.reset_p.password != this.reset_p.confirm_password) {
      this.error_notification(this.i18n.t('text_passwords_do_not_match'));
      return;
    }
    this.ui_controls.loading = true;
    this.networkService.post_request(this.reset_p, GlobalComponent.UserReset)
      .subscribe(({
        next: (response) => {
          if (response.response_code === 200 && response.status === "success") {
            this.ui_controls.loading = false;
            this.success_notification(response.message);
            this.router.navigate(['/login']);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  getAuthToken() {
    this.ui_controls.loading = true;
    this.networkService.get_request(GlobalComponent.getToken)
      .subscribe(({
        next: (response) => {
          if (response.response_code == 200 && response.status == "success") {
            this.smsToken = response;
            this.ui_controls.loading = false;
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  send_otp() {
    if (this.reset_p.phone.length === 0) {
      this.error_notification(this.i18n.t('text_phone_required'));
      return;
    }
    if (this.smsToken.data.length === 0) {
      this.error_notification(this.i18n.t('text_request_failed'));
      return;
    }
    this.sendOTP.number = this.reset_p.phone;
    this.sendOTP.token = this.smsToken.data;
    this.ui_controls.loading = true;
    this.networkService.post_request(this.sendOTP, GlobalComponent.sendOOTP)
      .subscribe(({
        next: (response) => {
          if (response.responseCode === 200 && response.message === "SUCCESS") {
            this.r_response = response;
            this.ui_controls.loading = false;
            this.ui_controls.otpSent = true;
            this.ui_controls.otpValidated = false;
            this.success_notification(response.message);
          }else{
            this.ui_controls.loading = false;
            this.ui_controls.otpSent = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  validate_otp() {
    if (this.validateOtp.code.length === 0) {
      this.error_notification(this.i18n.t('text_otp_required'));
      return;
    }
    this.validateOtp.token = this.smsToken.data;
    this.validateOtp.verificationId = this.r_response.data.verificationId;
    this.ui_controls.loading = true;
    this.networkService.post_request(this.validateOtp, GlobalComponent.validateOTP)
      .subscribe(({
        next: (response) => {
          if (response.responseCode === 200 && response.message === "SUCCESS") {
            this.v_response = response;
            this.ui_controls.loading = false;
            this.ui_controls.otpValidated = true;
            this.success_notification(this.i18n.t('text_code_validated'));
          }else {
            this.ui_controls.loading = false;
            this.ui_controls.otpValidated = false;
            this.error_notification(response.message);
          }
        },
        error: (e) => {
          console.error(e);
          this.error_notification(e);
          this.ui_controls.loading = false;
        },
        complete: () => {
          console.info('complete');
        }
      }))
  }
  error_notification(message: string) {
    this.toast.error(message, {
      position: 'top-center'
    });
  }
  success_notification(message: string) {
    this.toast.success(message, {
      position: 'top-center'
    });
  }
  sign_in() {
    this.router.navigate(['/', 'login']);
  }
  user_register() {
    this.router.navigate(['/', 'register']);
  }

}
