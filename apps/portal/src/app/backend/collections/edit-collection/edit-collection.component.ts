import { Component, OnInit } from '@angular/core';
import { NgIf } from '@angular/common';
import { FormsModule, ReactiveFormsModule } from '@angular/forms';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../../global-component';
import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-edit-collection',
  standalone: true,
  imports: [AdminShellComponent, NgIf, ReactiveFormsModule, RouterLink, FormsModule],
  templateUrl: './edit-collection.component.html',
  styleUrl: './edit-collection.component.css',
})
export class EditCollectionComponent implements OnInit {
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

  update = {
    id: 0,
    token: '',
    is_active: false,
    collection: 0,
    name: '',
  };

  read = {
    id: 0,
    token: '',
    collection: 0,
  };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.update.id = this.user_session.id;
    this.update.token = this.user_session.token;
    this.read.id = this.user_session.id;

    const collectionId = localStorage.getItem('ID');
    const collectionStatus = localStorage.getItem('STATUS');
    this.read.token = this.user_session.token;
    this.read.collection = collectionId !== null ? Number(collectionId) : 0;
    this.update.collection = collectionId !== null ? Number(collectionId) : 0;
    this.update.is_active = collectionStatus !== null ? Boolean(collectionStatus) : false;
    this.getCollection();
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

  updateCollection() {
    if (this.update.name.length === 0) {
      this.error_notification('Collection name cannot be empty');
      return;
    }
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.update, GlobalComponent.updateCollection).subscribe({
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

  getCollection() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.read, GlobalComponent.getCollection).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.update = response.data;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }
}
