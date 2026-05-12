import { Component, OnInit } from '@angular/core';
import { CrudService } from '../../services/crud.service';
import { Router, RouterLink } from '@angular/router';
import { CookieService } from 'ngx-cookie-service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { LanguageSwitcherComponent } from '../../language-switcher.component';
import { TranslatePipe } from '../../translate.pipe';
import { DIAL_CODES, DialCode } from '../../dial-codes';
import {
  AxDropdownDirective,
  AxDropdownItemDirective,
} from '../../shared/overlays';
import { AxEmptyStateComponent } from '../../shared/data';

@Component({
  selector: 'app-register',
  standalone: true,
  imports: [
    CommonModule,
    FormsModule,
    RouterLink,
    LanguageSwitcherComponent,
    TranslatePipe,
    AxDropdownDirective,
    AxDropdownItemDirective,
    AxEmptyStateComponent,
  ],
  templateUrl: './register.component.html',
  styleUrl: './register.component.css',
})
export class RegisterComponent implements OnInit {
  constructor(
    private crudService: CrudService,
    private router: Router,
    private cookieService: CookieService,
    private toast: HotToastService,
  ) {}

  fieldTextType = false;
  dialCodes: DialCode[] = DIAL_CODES;
  codeSearch = '';

  get selectedDial(): DialCode | undefined {
    return this.dialCodes.find(d => d.code === this.register.countryCode);
  }

  filteredDialCodes(): DialCode[] {
    const q = this.codeSearch.trim().toLowerCase();
    if (!q) return this.dialCodes;
    return this.dialCodes.filter(
      d => d.name.toLowerCase().includes(q) || d.code.includes(q),
    );
  }

  selectCode(d: DialCode) {
    this.register.countryCode = d.code;
    this.codeSearch = '';
  }

  ui_controls = {
    loading: false,
    registered: false,
  };

  register = {
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    password: '',
    confirm_password: '',
    countryCode: '+971',
    business_name: '',
    license_number: '',
  };

  ngOnInit(): void {}

  user_register() {
    if (this.register.first_name.length === 0) {
      this.error_notification('First name is required');
      return;
    }
    if (this.register.last_name.length === 0) {
      this.error_notification('Last name is required');
      return;
    }
    if (this.register.email.length === 0) {
      this.error_notification('Email address is required');
      return;
    }
    if (!GlobalComponent.validateEmail(this.register.email)) {
      this.error_notification('Invalid email format provided');
      return;
    }
    if (this.register.phone.length === 0) {
      this.error_notification('Phone number is required');
      return;
    }
    if (this.register.business_name.length === 0) {
      this.error_notification('Business name is required');
      return;
    }
    if (this.register.license_number.length === 0) {
      this.error_notification('Business license is required');
      return;
    }
    if (this.register.countryCode.length === 0) {
      this.error_notification('Country code is required');
      return;
    }
    if (!GlobalComponent.validateNumber(this.register.phone)) {
      this.error_notification('Invalid phone number format provided');
      return;
    }
    if (this.register.password.length === 0) {
      this.error_notification('Password is required');
      return;
    }
    if (this.register.confirm_password.length === 0) {
      this.error_notification('Password does not match');
      return;
    }
    if (this.register.password !== this.register.confirm_password) {
      this.error_notification('Password does not match');
      return;
    }
    this.ui_controls.loading = true;
    this.crudService.post_request(this.register, GlobalComponent.UserRegister).subscribe({
      next: (response) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.ui_controls.loading = false;
          this.ui_controls.registered = true;
          this.success_notification(response.message);
          this.router.navigate(['/login']).then(r => console.log(r));
        } else {
          this.error_notification(response.message);
          this.ui_controls.loading = false;
        }
      },
      error: (e) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.loading = false;
      },
      complete: () => {
        console.info('complete');
      },
    });
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }
}
