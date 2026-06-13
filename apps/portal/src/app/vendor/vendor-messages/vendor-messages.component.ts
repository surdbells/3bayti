import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { HotToastService } from '../../shared/toast/toast.service';

import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';
@Component({
  selector: 'app-vendor-messages',
  imports: [VendorShellComponent, IconComponent],
  standalone: true,
  templateUrl: './vendor-messages.component.html',
  styleUrl: './vendor-messages.component.css',
})
export class VendorMessagesComponent implements OnInit {
  constructor(
    private router: Router,
    private toast: HotToastService,
  ) {}

  ui_controls = {
    is_loading: false,
  };

  ngOnInit(): void {}

  goBack() {
    this.router.navigate(['/account']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }
}
