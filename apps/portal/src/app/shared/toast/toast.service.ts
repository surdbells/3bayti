/**
 * Drop-in replacement for @ngneat/hot-toast.
 *
 * Implements only the methods the portal uses: success() and error().
 * Renders a lightweight overlay toast with auto-dismiss — no third-party
 * dependencies, no Angular version conflicts.
 *
 * Usage is identical to HotToastService:
 *   this.toast.success('Saved!');
 *   this.toast.error('Something went wrong.');
 */
import { Injectable } from '@angular/core';

@Injectable({ providedIn: 'root' })
export class HotToastService {
  private container: HTMLElement | null = null;

  private getContainer(): HTMLElement {
    if (!this.container || !document.body.contains(this.container)) {
      const el = document.createElement('div');
      el.setAttribute('aria-live', 'polite');
      el.setAttribute('aria-atomic', 'false');
      el.style.cssText = [
        'position:fixed', 'top:1.25rem', 'right:1.25rem',
        'z-index:99999', 'display:flex', 'flex-direction:column',
        'gap:0.5rem', 'pointer-events:none',
      ].join(';');
      document.body.appendChild(el);
      this.container = el;
    }
    return this.container;
  }

  private show(message: string, type: 'success' | 'error'): void {
    const container = this.getContainer();

    const toast = document.createElement('div');
    const isSuccess = type === 'success';

    const icon  = isSuccess ? '✓' : '✕';
    const bg    = isSuccess ? '#059669' : '#DC2626';
    const iconBg = isSuccess ? 'rgba(255,255,255,0.25)' : 'rgba(255,255,255,0.25)';

    toast.setAttribute('role', 'alert');
    toast.style.cssText = [
      `background:${bg}`,
      'color:#fff',
      'padding:0.625rem 1rem',
      'border-radius:0.5rem',
      'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif',
      'font-size:0.875rem',
      'font-weight:500',
      'display:flex',
      'align-items:center',
      'gap:0.5rem',
      'pointer-events:auto',
      'box-shadow:0 4px 12px rgba(0,0,0,0.18)',
      'max-width:22rem',
      'opacity:0',
      'transform:translateX(100%)',
      'transition:opacity 200ms ease,transform 200ms ease',
      'cursor:pointer',
    ].join(';');

    const iconEl = document.createElement('span');
    iconEl.textContent = icon;
    iconEl.style.cssText = [
      `background:${iconBg}`,
      'border-radius:50%',
      'width:1.25rem', 'height:1.25rem',
      'display:flex', 'align-items:center', 'justify-content:center',
      'font-size:0.75rem', 'flex-shrink:0',
    ].join(';');

    const text = document.createElement('span');
    text.textContent = message;

    toast.appendChild(iconEl);
    toast.appendChild(text);
    container.appendChild(toast);

    // Animate in
    requestAnimationFrame(() => {
      requestAnimationFrame(() => {
        toast.style.opacity = '1';
        toast.style.transform = 'translateX(0)';
      });
    });

    const dismiss = () => {
      toast.style.opacity = '0';
      toast.style.transform = 'translateX(100%)';
      setTimeout(() => toast.remove(), 210);
    };

    toast.addEventListener('click', dismiss);
    setTimeout(dismiss, type === 'error' ? 5000 : 3500);
  }

  success(message: string): void {
    this.show(message, 'success');
  }

  error(message: string): void {
    this.show(message, 'error');
  }
}

/**
 * No-op replacement for provideHotToastConfig().
 * The service is providedIn:'root' so no extra provider is needed.
 */
export function provideHotToastConfig(): never[] {
  return [];
}
