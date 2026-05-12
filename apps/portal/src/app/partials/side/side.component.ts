import { Component, EventEmitter, Input, OnInit, Output } from '@angular/core';
import { Router, RouterLink, RouterLinkActive } from '@angular/router';
import { CommonModule } from '@angular/common';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { TranslatePipe } from '../../translate.pipe';

@Component({
  selector: 'app-side',
  imports: [
    CommonModule,
    RouterLink,
    RouterLinkActive,
    TranslatePipe,
  ],
  standalone: true,
  templateUrl: './side.component.html',
  styleUrl: './side.component.css',
})
export class SideComponent implements OnInit {
  /** Mobile drawer open state. Controlled by parent (topbar burger). */
  @Input() isOpen = false;
  @Output() isOpenChange = new EventEmitter<boolean>();

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
  };

  session_data: any = '';
  user_session = {
    id: 0,
    token: '',
    first_name: '',
    last_name: '',
    email: '',
    phone: '',
    is_2fa: false,
    is_active: false,
    is_admin: false,
    is_vendor: false,
    is_customer: false,
  };

  ngOnInit(): void {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  close(): void {
    this.isOpen = false;
    this.isOpenChange.emit(false);
  }

  open(): void {
    this.isOpen = true;
    this.isOpenChange.emit(true);
  }

  sign_out(): void {
    localStorage.clear();
    sessionStorage.clear();
    this.success_notification('User logged out successfully.');
    this.router.navigate(['/']).then(r => console.log(r));
  }
}
