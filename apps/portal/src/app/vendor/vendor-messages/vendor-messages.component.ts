import { Component, OnInit } from '@angular/core';
import { Router } from '@angular/router';
import { CommonModule } from '@angular/common';

import { PortalCrudAdapter } from '../../services/portal-crud-adapter';
import { HotToastService } from '../../shared/toast/toast.service';
import { VendorShellComponent } from '../../partials/vendor-shell/vendor-shell.component';
import { IconComponent } from '../../shared/icon/icon.component';

interface VendorMessageRow {
  id: number;
  subject: string | null;
  message: string;
  is_read: boolean;
  created: string;
}

/**
 * Vendor message inbox — direct messages from platform admins. Reads
 * GET /vendor/messages and marks individual messages read via
 * POST /vendor/messages/:id/read.
 */
@Component({
  selector: 'app-vendor-messages',
  standalone: true,
  imports: [VendorShellComponent, CommonModule, IconComponent],
  templateUrl: './vendor-messages.component.html',
  styleUrl: './vendor-messages.component.css',
})
export class VendorMessagesComponent implements OnInit {
  messages: VendorMessageRow[] = [];
  unread = 0;
  ui_controls = { is_loading: false };

  constructor(
    private router: Router,
    private adapter: PortalCrudAdapter,
    private toast: HotToastService,
  ) {}

  ngOnInit(): void {
    this.fetchMessages();
  }

  fetchMessages(): void {
    this.ui_controls.is_loading = true;
    this.adapter.get_v3('GET /vendor/messages', { query: { limit: 50, offset: 0 } }).subscribe({
      next: (r: any) => {
        this.messages = (r?.data ?? []).map((m: any) => ({
          id: m.id,
          subject: m.subject ?? null,
          message: m.message ?? m.body ?? '',
          is_read: !!m.is_read,
          created: m.created ?? m.created_at ?? '',
        }));
        this.unread = r?.meta?.unread ?? this.messages.filter((m) => !m.is_read).length;
        this.ui_controls.is_loading = false;
      },
      error: () => {
        this.toast.error('Unable to load your messages.');
        this.ui_controls.is_loading = false;
      },
    });
  }

  markRead(msg: VendorMessageRow): void {
    if (msg.is_read) return;
    this.adapter.post_v3('POST /vendor/messages/:id/read', {}, { params: { id: String(msg.id) } }).subscribe({
      next: () => {
        msg.is_read = true;
        this.unread = Math.max(0, this.unread - 1);
      },
      error: () => this.toast.error('Unable to mark the message as read.'),
    });
  }

  goBack(): void {
    this.router.navigate(['/account']);
  }
}
