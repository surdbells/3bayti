import { describe, it, expect, afterEach } from 'vitest';
import { TestBed, ComponentFixture } from '@angular/core/testing';
import { provideRouter } from '@angular/router';

import { ButtonComponent } from './button';

/**
 * ui-button `disabled` vs `busy`: a native `disabled` button drops keyboard
 * focus to <body>, so transient states (a request in flight) use `busy`,
 * which announces as unavailable (aria-disabled) and ignores clicks but
 * stays focusable.
 */
function setup(inputs: { disabled?: boolean; busy?: boolean }): {
  fixture: ComponentFixture<ButtonComponent>;
  button: HTMLButtonElement;
  clicks: MouseEvent[];
} {
  TestBed.configureTestingModule({
    imports: [ButtonComponent],
    providers: [provideRouter([])],
  });
  const fixture = TestBed.createComponent(ButtonComponent);
  for (const [k, v] of Object.entries(inputs)) fixture.componentRef.setInput(k, v);
  const clicks: MouseEvent[] = [];
  fixture.componentInstance.clicked.subscribe((e) => clicks.push(e));
  fixture.detectChanges();
  const button = fixture.nativeElement.querySelector('button') as HTMLButtonElement;
  return { fixture, button, clicks };
}

describe('ButtonComponent busy state', () => {
  afterEach(() => TestBed.resetTestingModule());

  it('emits clicks when neither disabled nor busy', () => {
    const { button, clicks } = setup({});
    button.click();
    expect(clicks).toHaveLength(1);
    expect(button.disabled).toBe(false);
    expect(button.getAttribute('aria-disabled')).toBeNull();
  });

  it('busy: stays focusable (not natively disabled), announces aria-disabled and ignores clicks', () => {
    const { button, clicks } = setup({ busy: true });
    expect(button.disabled).toBe(false);
    expect(button.getAttribute('aria-disabled')).toBe('true');
    button.click();
    expect(clicks).toHaveLength(0);
  });

  it('keeps focus on the button when it turns busy after being pressed', () => {
    const { fixture, button } = setup({});
    document.body.appendChild(fixture.nativeElement);
    button.focus();
    expect(document.activeElement).toBe(button);
    fixture.componentRef.setInput('busy', true);
    fixture.detectChanges();
    expect(document.activeElement).toBe(button);
    fixture.nativeElement.remove();
  });

  it('disabled: natively disabled, no redundant aria-disabled, ignores clicks', () => {
    const { button, clicks } = setup({ disabled: true, busy: true });
    expect(button.disabled).toBe(true);
    expect(button.getAttribute('aria-disabled')).toBeNull();
    button.click();
    expect(clicks).toHaveLength(0);
  });
});
