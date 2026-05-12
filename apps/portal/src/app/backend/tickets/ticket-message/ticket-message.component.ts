import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { CrudService } from '../../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../../global-component';

import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
export interface Message {
  id: number;
  ticket: number;
  sender: string;
  message: string;
  timestamp: string;
}

@Component({
  selector: 'app-ticket-message',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule],
  templateUrl: './ticket-message.component.html',
  styleUrl: './ticket-message.component.css',
})
export class TicketMessageComponent implements OnInit {
  message?: Message[];

  ui_controls = {
    is_loading: false,
    sending: false,
    no_data: false,
    nav_open: false,
  };

  session_data: any = '';
  ticket_name: any = '';
  user_session = {
    id: 0, token: '', first_name: '', last_name: '',
    email: '', phone: '',
    is_2fa: false, is_active: false, is_admin: false,
    is_vendor: false, is_customer: false,
  };

  get_msg_t = { id: 0, token: '', ticket: 0 };
  add_message = { id: 0, token: '', ticket: 0, message: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private route: ActivatedRoute,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    const ticketId = Number(this.route.snapshot.queryParamMap.get('id'));
    this.ticket_name = this.route.snapshot.queryParamMap.get('name');

    this.get_msg_t.token = this.user_session.token;
    this.get_msg_t.id = this.user_session.id;
    this.get_msg_t.ticket = ticketId;

    this.add_message.token = this.user_session.token;
    this.add_message.id = this.user_session.id;
    this.add_message.ticket = ticketId;
    this.get_ticket_messages();
  }

  goBack() {
    this.router.navigate(['/admintickets']).then(r => console.log(r));
  }

  error_notification(message: string) {
    this.toast.error(message);
  }

  success_notification(message: string) {
    this.toast.success(message);
  }

  get_ticket_messages() {
    this.ui_controls.is_loading = true;
    this.crudService.post_request(this.get_msg_t, GlobalComponent.ticketsMessages).subscribe({
      next: (response: any) => {
        this.message = response.data;
        this.ui_controls.no_data = !this.message || this.message.length === 0;
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
      },
    });
  }

  send_messages() {
    if (!this.add_message.message || this.add_message.message.trim().length === 0) {
      this.error_notification('Message cannot be empty.');
      return;
    }
    this.ui_controls.sending = true;
    this.crudService.post_request(this.add_message, GlobalComponent.sendTicketMessage).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.add_message.message = '';
          this.success_notification(response.message);
          this.get_ticket_messages();
        }
        this.ui_controls.sending = false;
      },
      error: () => {
        this.ui_controls.sending = false;
      },
    });
  }

  isSupport(sender: string): boolean {
    // Best-effort: treat messages from admin/support users as inbound bubbles on the left
    const s = (sender || '').toLowerCase();
    return s.includes('support') || s.includes('admin') || s.includes('system');
  }
}
