import { ComponentFixture, TestBed, fakeAsync, flush } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';
import { OverlayContainer } from '@angular/cdk/overlay';
import { Platform } from '@ionic/angular/standalone';
import { Subscription } from 'rxjs';

import { AIN_CONCIERGE_TOOLS, AxAinConciergeSheetComponent } from './ax-ain-concierge-sheet.component';
import { I18nService } from '../../../i18n.service';
import { provideAxIcons } from '../icon';

class I18nStub {
  lang = 'en';
  t = (key: string) => key;
}

/** Captures the hardware-back handler the sheet registers. */
class PlatformStub {
  priority: number | null = null;
  handler: (() => void) | null = null;
  readonly sub = new Subscription();
  backButton = {
    subscribeWithPriority: (priority: number, handler: () => void) => {
      this.priority = priority;
      this.handler = handler;
      return this.sub;
    },
  };
}

describe('AxAinConciergeSheetComponent', () => {
  let fixture: ComponentFixture<AxAinConciergeSheetComponent>;
  let component: AxAinConciergeSheetComponent;
  let platform: PlatformStub;
  let navSpy: jasmine.Spy;
  let overlayEl: HTMLElement;
  let dismissed: number;

  beforeEach(() => {
    platform = new PlatformStub();
    TestBed.configureTestingModule({
      imports: [AxAinConciergeSheetComponent],
      providers: [
        provideRouter([]),
        provideAxIcons(),
        { provide: I18nService, useValue: new I18nStub() },
        { provide: Platform, useValue: platform },
      ],
    });
    fixture = TestBed.createComponent(AxAinConciergeSheetComponent);
    component = fixture.componentInstance;
    navSpy = spyOn(TestBed.inject(Router), 'navigate').and.resolveTo(true);
    overlayEl = TestBed.inject(OverlayContainer).getContainerElement();
    dismissed = 0;
    component.dismiss.subscribe(() => dismissed++);
    fixture.detectChanges();
  });

  afterEach(() => {
    fixture.destroy();
  });

  function open(): void {
    fixture.componentRef.setInput('isOpen', true);
    fixture.detectChanges();
  }

  it('renders nothing until opened', () => {
    expect(component.presented).toBeFalse();
    expect(overlayEl.querySelector('.ax-ain-sheet')).toBeNull();
  });

  it('opens a labelled dialog listing the four Ain tools with descriptions and examples', () => {
    open();
    const dialog = overlayEl.querySelector<HTMLElement>('.ax-ain-sheet');
    expect(dialog).not.toBeNull();
    expect(dialog!.getAttribute('role')).toBe('dialog');
    expect(dialog!.getAttribute('aria-modal')).toBe('true');
    const titleId = dialog!.getAttribute('aria-labelledby')!;
    expect(overlayEl.querySelector(`[id="${titleId}"]`)?.textContent).toContain('ain_sheet_title');
    // Focus moves into the dialog so it is announced.
    expect(document.activeElement).toBe(dialog);

    const rows = Array.from(overlayEl.querySelectorAll<HTMLElement>('.ax-ain-tool'));
    expect(rows.length).toBe(4);
    AIN_CONCIERGE_TOOLS.forEach((tool, i) => {
      expect(rows[i].classList).toContain('ax-ain-tool--' + tool.key);
      expect(rows[i].textContent).toContain(tool.titleKey);
      expect(rows[i].textContent).toContain(tool.descKey);
      expect(rows[i].textContent).toContain(tool.exampleKey);
    });
  });

  it('routes each tool exactly like the old inline cards', () => {
    expect(AIN_CONCIERGE_TOOLS.map((t) => t.route)).toEqual(['/ask-ain', '/gift-ain', '/outfit', '/visual-search']);
  });

  it('closes and navigates when a tool is tapped', () => {
    const picked: string[] = [];
    component.toolSelected.subscribe((t) => picked.push(t.key));
    open();
    overlayEl.querySelectorAll<HTMLButtonElement>('.ax-ain-tool')[1].click();

    expect(navSpy).toHaveBeenCalledWith(['/gift-ain']);
    expect(picked).toEqual(['gift']);
    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
    expect(overlayEl.querySelector('.ax-ain-sheet')).toBeNull();
  });

  it('closes on the close button and returns focus to the opener', fakeAsync(() => {
    const opener = document.createElement('button');
    document.body.appendChild(opener);
    opener.focus();
    open();

    overlayEl.querySelector<HTMLButtonElement>('.ax-ain-sheet__close')!.click();
    flush();

    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
    expect(document.activeElement).toBe(opener);
    expect(navSpy).not.toHaveBeenCalled();
    opener.remove();
  }));

  it('returns focus into a shadow-DOM opener (the ion-fab-button inner button)', fakeAsync(() => {
    // ion-fab-button: the focusable element is a <button> inside an open
    // shadow root without delegatesFocus, so document.activeElement is the
    // unfocusable host while the inner button has focus.
    const host = document.createElement('div');
    document.body.appendChild(host);
    const inner = document.createElement('button');
    host.attachShadow({ mode: 'open' }).appendChild(inner);
    inner.focus();
    expect(document.activeElement).toBe(host);
    open();

    overlayEl.querySelector<HTMLButtonElement>('.ax-ain-sheet__close')!.click();
    flush();

    expect(document.activeElement).toBe(host);
    expect(host.shadowRoot!.activeElement).toBe(inner);
    host.remove();
  }));

  it('leaves out the tools a page hides (guest home hides Search by photo)', () => {
    fixture.componentRef.setInput('hiddenTools', ['photo']);
    open();
    const keys = Array.from(overlayEl.querySelectorAll<HTMLElement>('.ax-ain-tool'))
      .map((row) => AIN_CONCIERGE_TOOLS.find((t) => row.classList.contains('ax-ain-tool--' + t.key))?.key);
    expect(keys).toEqual(['ask', 'gift', 'style']);
  });

  it('closes on Escape', fakeAsync(() => {
    open();
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape' }));
    flush();
    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
  }));

  it('closes on a backdrop tap', fakeAsync(() => {
    open();
    overlayEl.querySelector<HTMLElement>('.ax-ain-sheet__backdrop')!.click();
    flush();
    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
  }));

  it('closes on the hardware back button, above the homes back-button blocker', fakeAsync(() => {
    open();
    // BlockerService swallows back presses at 9999 on both homes.
    expect(platform.priority).toBeGreaterThan(9999);
    platform.handler!();
    flush();
    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
    expect(platform.sub.closed).toBeTrue();
  }));

  it('closes when the parent flips isOpen back to false', fakeAsync(() => {
    open();
    fixture.componentRef.setInput('isOpen', false);
    fixture.detectChanges();
    flush();
    expect(dismissed).toBe(1);
    expect(component.presented).toBeFalse();
  }));
});
