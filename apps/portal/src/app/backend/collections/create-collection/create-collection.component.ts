import { Component, OnInit } from '@angular/core';
import { NgIf } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { FormsModule } from '@angular/forms';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-create-collection',
  standalone: true,
  imports: [AdminShellComponent, NgIf, RouterLink, FormsModule],
  templateUrl: './create-collection.component.html',
  styleUrl: './create-collection.component.css',
})
export class CreateCollectionComponent implements OnInit {
  ui_controls = {
    is_loading: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  create = { id: 0, token: '', collection: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.create.id = this.user_session.id;
    this.create.token = this.user_session.token;
  }

  goBack() {
    this.router.navigate(['/collections']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  createCollection() {
    if (this.create.collection.length === 0) {
      this.error_notification('Collection name cannot be empty');
      return;
    }
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.create, GlobalComponent.createCollection).subscribe({
      next: (response: any) => {
        this.ui_controls.is_loading = false;
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.router.navigate(['/collections']).then(r => console.log(r));
        } else if ((response.response_code === 200 || response.response_code === 400) && response.status === 'failed') {
          this.error_notification(response.message);
        }
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
      },
    });
  }
}
