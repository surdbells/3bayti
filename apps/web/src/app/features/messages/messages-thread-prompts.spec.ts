import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { ActivatedRoute } from '@angular/router';
import { signal } from '@angular/core';
import { MessagesThreadPageComponent } from './messages-thread-page';
import { MessagesService } from './messages.service';
import { ToastService } from '../../shared/forms';
import { provideI18n } from '../../core/i18n';
import type { ChatMessage, ChatPromptCategory, ConversationSummary, ThreadResult } from './messages.service';

function conv(): ConversationSummary {
  return {
    uuid: 'uuid-1', status: 'active', order_reference: '3B-0001', unread_count: 0,
    last_message_at: null, last_message_preview: null,
    counterparty: { type: 'vendor', name: 'Atelier Noor', slug: 'atelier-noor', logo_url: null },
    item: { name: 'Silk Abaya', image: null, size: null, color: null },
    created_at: '2026-09-23T09:00:00Z',
  };
}

function msg(o: Partial<ChatMessage> = {}): ChatMessage {
  return {
    id: 1, uuid: 'm1', sender_type: 'vendor', type: 'text',
    content: 'Hi', content_ar: null, is_flagged: false, status: 'sent',
    created_at: '2026-09-23T10:00:00Z', ...o,
  };
}

const CATS: ChatPromptCategory[] = [
  {
    id: 1, slug: 'order_status', label: 'Order status', label_ar: 'حالة الطلب', icon: 'package',
    prompts: [
      { id: 11, slug: 'where', text: 'Where is my order?', text_ar: 'أين طلبي؟' },
      { id: 12, slug: 'when', text: 'When will it be delivered?', text_ar: 'متى؟' },
    ],
  },
];

class StubMessages {
  isLoading = signal(false).asReadonly();
  isSending = signal(false).asReadonly();
  thread: ThreadResult = { conversation: conv(), messages: [msg()] };
  catalog: ChatPromptCategory[] = CATS;
  sendPromptCalls: Array<[string, number]> = [];

  async getMessages(): Promise<ThreadResult> { return this.thread; }
  async markAsRead(): Promise<void> {}
  async getPromptCatalog(): Promise<ChatPromptCategory[]> { return this.catalog; }
  async sendPrompt(uuid: string, id: number): Promise<ChatMessage> {
    this.sendPromptCalls.push([uuid, id]);
    return msg({ id: 99, sender_type: 'customer', type: 'prompt', content: 'Where is my order?' });
  }
  async sendMessage(): Promise<ChatMessage> { return msg(); }
}

class StubToast {
  calls: string[] = [];
  success(m: string): string { this.calls.push('success:' + m); return ''; }
  error(m: string): string { this.calls.push('error:' + m); return ''; }
  info(m: string): string { this.calls.push('info:' + m); return ''; }
  warning(m: string): string { this.calls.push('warning:' + m); return ''; }
}

function setup(catalog: ChatPromptCategory[] = CATS): {
  fixture: ComponentFixture<MessagesThreadPageComponent>;
  messages: StubMessages;
} {
  const messages = new StubMessages();
  messages.catalog = catalog;
  TestBed.configureTestingModule({
    imports: [MessagesThreadPageComponent],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      provideHttpClientTesting(),
      provideI18n(),
      { provide: MessagesService, useValue: messages },
      { provide: ToastService, useValue: new StubToast() },
      { provide: ActivatedRoute, useValue: { snapshot: { paramMap: { get: () => 'uuid-1' } } } },
    ],
  });
  const fixture = TestBed.createComponent(MessagesThreadPageComponent);
  fixture.detectChanges();
  return { fixture, messages };
}

async function flush(): Promise<void> {
  for (let i = 0; i < 10; i++) await Promise.resolve();
}

describe('MessagesThreadPageComponent — quick-start prompts', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach((req) => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('shows the prompt toggle once the catalog loads', async () => {
    const { fixture } = setup();
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="chat-prompts-toggle"]')).not.toBeNull();
  });

  it('hides the picker entirely when the catalog is empty', async () => {
    const { fixture } = setup([]);
    await flush();
    fixture.detectChanges();
    expect(fixture.nativeElement.querySelector('[data-testid="chat-prompts"]')).toBeNull();
  });

  it('expands the panel and sends a tapped prompt', async () => {
    const { fixture, messages } = setup();
    await flush();
    fixture.detectChanges();
    (fixture.nativeElement.querySelector('[data-testid="chat-prompts-toggle"]') as HTMLButtonElement).click();
    fixture.detectChanges();
    const chip = fixture.nativeElement.querySelector('.message-prompts__chip') as HTMLButtonElement;
    expect(chip).not.toBeNull();
    expect(chip.textContent).toContain('Where is my order?');
    chip.click();
    await flush();
    fixture.detectChanges();
    expect(messages.sendPromptCalls).toEqual([['uuid-1', 11]]);
    // Picker collapses after sending.
    expect(fixture.nativeElement.querySelector('.message-prompts__panel')).toBeNull();
    fixture.destroy();
  });
});
