import { describe, it, expect, afterEach, vi } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { Component } from '@angular/core';
import { HttpTestingController, provideHttpClientTesting } from '@angular/common/http/testing';
import { provideHttpClient } from '@angular/common/http';
import { ConfirmModalComponent } from './confirm-modal';
import { provideI18n } from '../../core/i18n';

/** Host harness so we can drive inputs/outputs declaratively. */
@Component({
  standalone: true,
  imports: [ConfirmModalComponent],
  template: `
    <ui-confirm-modal
      [open]="open"
      [title]="'common.confirm'"
      [message]="'common.confirm'"
      [danger]="danger"
      [busy]="busy"
      (confirm)="onConfirm()"
      (cancel)="onCancel()"
    ></ui-confirm-modal>
  `,
})
class HostComponent {
  open = false;
  danger = false;
  busy = false;
  confirmCount = 0;
  cancelCount = 0;
  onConfirm(): void { this.confirmCount++; }
  onCancel(): void { this.cancelCount++; }
}

function setup(): { fixture: ComponentFixture<HostComponent>; host: HostComponent } {
  TestBed.configureTestingModule({
    imports: [HostComponent],
    providers: [provideHttpClient(), provideHttpClientTesting(), provideI18n()],
  });
  const fixture = TestBed.createComponent(HostComponent);
  /* NB: callers set host inputs BEFORE the first detectChanges() to
     avoid a dev-mode checkNoChanges conflict on the host's [open]
     binding. */
  return { fixture, host: fixture.componentInstance };
}

function q(fixture: ComponentFixture<HostComponent>, testid: string): HTMLElement | null {
  return fixture.nativeElement.querySelector(`[data-testid="${testid}"]`);
}

describe('ConfirmModalComponent', () => {
  afterEach(() => {
    try {
      const controller = TestBed.inject(HttpTestingController);
      controller.match(() => true).forEach(req => { if (!req.cancelled) req.flush({}); });
    } catch { /* ignore */ }
    TestBed.resetTestingModule();
    vi.restoreAllMocks();
  });

  it('renders nothing when closed', () => {
    const { fixture } = setup();
    fixture.detectChanges();
    expect(q(fixture, 'confirm-modal')).toBeNull();
  });

  it('renders the dialog when open', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    const dialog = q(fixture, 'confirm-modal');
    expect(dialog).not.toBeNull();
    expect(dialog?.getAttribute('role')).toBe('dialog');
    expect(dialog?.getAttribute('aria-modal')).toBe('true');
  });

  it('emits confirm when the confirm button is clicked', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-confirm') as HTMLButtonElement).click();
    expect(host.confirmCount).toBe(1);
    expect(host.cancelCount).toBe(0);
  });

  it('emits cancel when the cancel button is clicked', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    (q(fixture, 'confirm-modal-cancel') as HTMLButtonElement).click();
    expect(host.cancelCount).toBe(1);
    expect(host.confirmCount).toBe(0);
  });

  it('emits cancel when the backdrop is clicked', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    const backdrop = q(fixture, 'confirm-modal-backdrop') as HTMLElement;
    backdrop.dispatchEvent(new MouseEvent('click', { bubbles: true }));
    fixture.detectChanges();
    expect(host.cancelCount).toBe(1);
  });

  it('does NOT cancel when a click originates inside the dialog', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    /* Click the dialog body (not the backdrop). */
    (q(fixture, 'confirm-modal') as HTMLElement).dispatchEvent(new MouseEvent('click', { bubbles: true }));
    fixture.detectChanges();
    expect(host.cancelCount).toBe(0);
  });

  it('does not emit confirm while busy', () => {
    const { fixture, host } = setup();
    host.open = true;
    host.busy = true;
    fixture.detectChanges();
    const confirmBtn = q(fixture, 'confirm-modal-confirm') as HTMLButtonElement;
    expect(confirmBtn.disabled).toBe(true);
    confirmBtn.click();
    expect(host.confirmCount).toBe(0);
  });

  it('applies the danger class when [danger] is set', () => {
    const { fixture, host } = setup();
    host.open = true;
    host.danger = true;
    fixture.detectChanges();
    const confirmBtn = q(fixture, 'confirm-modal-confirm') as HTMLButtonElement;
    expect(confirmBtn.classList.contains('confirm-modal__confirm--danger')).toBe(true);
  });

  it('wires aria-labelledby/describedby to the title/message ids', () => {
    const { fixture, host } = setup();
    host.open = true;
    fixture.detectChanges();
    const dialog = q(fixture, 'confirm-modal') as HTMLElement;
    const labelledby = dialog.getAttribute('aria-labelledby');
    const describedby = dialog.getAttribute('aria-describedby');
    expect(labelledby).not.toBeNull();
    expect(describedby).not.toBeNull();
    expect(dialog.querySelector(`#${labelledby}`)).not.toBeNull();
    expect(dialog.querySelector(`#${describedby}`)).not.toBeNull();
  });
});
