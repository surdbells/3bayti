import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterLink } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { Labels } from '../../class/labels';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
@Component({
  selector: 'app-collections',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, RouterLink],
  templateUrl: './collections.component.html',
  styleUrl: './collections.component.css',
})
export class CollectionsComponent implements OnInit {
  collections?: Labels[];

  ui_controls = {
    is_loading: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  collection = { id: 0, token: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.collection.id = this.user_session.id;
    this.collection.token = this.user_session.token;
    this.get_collections();
  }

  goBack() {
    this.router.navigate(['/backend']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_collections() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.collection, GlobalComponent.readCollection).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.collections = response.data;
          this.ui_controls.no_data = !this.collections || this.collections.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: (e: any) => {
        console.error(e);
        this.error_notification('Unable to complete your request at this time.');
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  edit_collection(id: number, label: string, is_active: boolean) {
    localStorage.setItem('ID', String(id));
    localStorage.setItem('NAME', label);
    localStorage.setItem('STATUS', String(is_active));
    this.router.navigate(['/edit_collection']).then(r => console.log(r));
  }
}
