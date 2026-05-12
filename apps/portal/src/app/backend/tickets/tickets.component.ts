import { Component, inject, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CrudService } from '../../services/crud.service';
import { HotToastService } from '@ngneat/hot-toast';
import { GlobalComponent } from '../../global-component';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { AxDropdownDirective, AxDropdownItemDirective } from '../../shared/overlays';

import { AxConfirmService } from '../../shared/overlays';
import { AdminShellComponent } from '../../partials/admin-shell/admin-shell.component';
export interface Tickets {
  ticket_id: number;
  ticket_ref: string;
  subject: string;
  message: string;
  priority: string;
  store_name: string;
  messages: number;
  status: string;
  created: string;
  updated: string;
  minutes_after_created: number;
  minutes_after_updated: number;
}

@Component({
  selector: 'app-tickets',
  standalone: true,
  imports: [
    AdminShellComponent,
    CommonModule,
    FormsModule,
    AxDropdownDirective,
    AxDropdownItemDirective,
  ],
  templateUrl: './tickets.component.html',
  styleUrl: './tickets.component.css',
})
export class TicketsComponent implements OnInit {
  tickets?: Tickets[];
  private readonly confirm = inject(AxConfirmService);

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

  get_ticket = { id: 0, token: '' };
  get_ticket_priority = { id: 0, token: '', priority: 'high' };
  get_ticket_status = { id: 0, token: '', status: 'open' };
  status = { id: 0, token: '', ticket: 0, status: '' };
  priority = { id: 0, token: '', ticket: 0, priority: '' };

  constructor(
    private router: Router,
    private crudService: CrudService,
    private toast: HotToastService,
  ) {}

  ngOnInit() {
    this.session_data = sessionStorage.getItem('SESSION');
    this.user_session = GlobalComponent.decodeBase64(this.session_data);
    this.get_ticket.id = this.user_session.id;
    this.get_ticket.token = this.user_session.token;
    this.get_ticket_status.id = this.user_session.id;
    this.get_ticket_status.token = this.user_session.token;
    this.get_ticket_priority.id = this.user_session.id;
    this.get_ticket_priority.token = this.user_session.token;
    this.status.id = this.user_session.id;
    this.status.token = this.user_session.token;
    this.priority.id = this.user_session.id;
    this.priority.token = this.user_session.token;
    this.get_tickets();
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

  get_tickets() {
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_ticket, GlobalComponent.tickets).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.tickets = response.data;
          this.ui_controls.no_data = !this.tickets || this.tickets.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  get_ticketPriority(event: Event) {
    this.get_ticket_priority.priority = (event.target as HTMLSelectElement).value;
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_ticket_priority, GlobalComponent.tickets).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.tickets = response.data;
          this.ui_controls.no_data = !this.tickets || this.tickets.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  get_ticketStatus(event: Event) {
    this.get_ticket_status.status = (event.target as HTMLSelectElement).value;
    this.ui_controls.is_loading = true;
    this.ui_controls.no_data = false;
    this.crudService.post_request(this.get_ticket_status, GlobalComponent.tickets).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.tickets = response.data;
          this.ui_controls.no_data = !this.tickets || this.tickets.length === 0;
        } else {
          this.ui_controls.no_data = true;
        }
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.ui_controls.is_loading = false;
        this.ui_controls.no_data = true;
      },
    });
  }

  start_changeStatus(ticket: number, subject: string, status: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `Ticket [ ${subject} ] status will be changed to ${status}`,
        confirmLabel: 'Continue',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.change_status(ticket, subject, status);
      });
  }

  change_status(ticket: number, _subject: string, status: string) {
    this.ui_controls.is_loading = true;
    this.status.status = status;
    this.status.ticket = ticket;
    this.crudService.post_request(this.status, GlobalComponent.ticketsStatus).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_tickets();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  start_changePriority(ticket: number, subject: string, priority: string) {
    this.confirm
      .confirm({
        title: 'Confirm',
        message: `Ticket [ ${subject} ] priority will be changed to ${priority}`,
        confirmLabel: 'Continue',
        cancelLabel: 'Cancel'
      })
      .then((response) => {
        if (response) this.change_priority(ticket, subject, priority);
      });
  }

  change_priority(ticket: number, _subject: string, priority: string) {
    this.ui_controls.is_loading = true;
    this.priority.ticket = ticket;
    this.priority.priority = priority;
    this.crudService.post_request(this.priority, GlobalComponent.ticketsPriority).subscribe({
      next: (response: any) => {
        if (response.response_code === 200 && response.status === 'success') {
          this.success_notification(response.message);
          this.get_tickets();
        }
        this.ui_controls.is_loading = false;
      },
    });
  }

  manage_ticket(id: number, name: string) {
    const urlTree = this.router.createUrlTree(['/ticket_messages'], { queryParams: { id, name } });
    window.open(this.router.serializeUrl(urlTree), '_blank');
  }

  priorityBadge(priority: string): string {
    switch (priority) {
      case 'low':     return 'ax-badge ax-badge-neutral';
      case 'medium':  return 'ax-badge ax-badge-warning';
      case 'high':    return 'ax-badge ax-badge-danger';
      case 'urgent':  return 'ax-badge ax-badge-solid-danger';
      default:        return 'ax-badge ax-badge-neutral';
    }
  }

  statusBadge(status: string): string {
    switch (status) {
      case 'open':     return 'ax-badge ax-badge-brand';
      case 'pending':  return 'ax-badge ax-badge-warning';
      case 'resolved': return 'ax-badge ax-badge-success';
      case 'closed':   return 'ax-badge ax-badge-neutral';
      default:         return 'ax-badge ax-badge-neutral';
    }
  }
}
