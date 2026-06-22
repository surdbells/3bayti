import { Component, OnInit } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { ActivatedRoute, Router } from '@angular/router';
import { PortalCrudAdapter } from '../../../services/portal-crud-adapter';
import { HotToastService } from '../../../shared/toast/toast.service';
import { GlobalComponent } from '../../../global-component';

import { AdminShellComponent } from '../../../partials/admin-shell/admin-shell.component';
import { IconComponent } from '../../../shared/icon/icon.component';
export interface Message {
  id: number;
  sender: string;
  message: string;
  timestamp: string;
  isAdmin: boolean;
}

@Component({
  selector: 'app-ticket-message',
  standalone: true,
  imports: [AdminShellComponent, CommonModule, FormsModule, IconComponent],
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
    private route: ActivatedRoute,
    private adapter: PortalCrudAdapter,
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
    const tmId = this.get_msg_t.ticket ?? this.get_msg_t.id;
    this.adapter.get_v3('GET /admin/tickets/:id/messages', { params: { id: String(tmId) } }).subscribe({
      next: (response: any) => {
        const raw: any[] = response?.data ?? [];
        this.message = raw.map((m): Message => ({
          id: m.id,
          sender: m.author_name,
          message: m.body,
          timestamp: m.created_at,
          isAdmin: !!m.is_admin_reply,
        }));
        this.ui_controls.no_data = this.message.length === 0;
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
    const tmSendId = this.add_message.ticket ?? this.add_message.id;
    this.adapter.post_v3('POST /admin/tickets/:id/messages', this.add_message, { params: { id: String(tmSendId) } }).subscribe({
      next: (response: any) => {
        if (response) {
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

  /**
   * The admin viewing this thread is the support side. Their own
   * replies (is_admin_reply) render as outbound bubbles on the right;
   * vendor/customer messages render inbound on the left.
   */
  isInbound(m: Message): boolean {
    return !m.isAdmin;
  }
}
