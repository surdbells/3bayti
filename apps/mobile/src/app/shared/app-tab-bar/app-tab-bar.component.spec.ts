import { TestBed } from '@angular/core/testing';
import { Router, provideRouter } from '@angular/router';

import { AppTabBarComponent } from './app-tab-bar.component';
import { CartCountService } from '../../core/services/cart-count.service';
import { PendingOrdersService } from '../../core/services/pending-orders.service';
import { I18nService } from '../../i18n.service';
import { provideAxIcons } from '../ax-mobile/icon';

class CartCountStub {
  refresh = jasmine.createSpy('refresh');
}

class PendingOrdersStub {
  count = () => 0;
}

class I18nStub {
  t = (key: string) => key;
}

function setup() {
  TestBed.configureTestingModule({
    imports: [AppTabBarComponent],
    providers: [
      provideRouter([]),
      provideAxIcons(),
      { provide: CartCountService, useValue: new CartCountStub() },
      { provide: PendingOrdersService, useValue: new PendingOrdersStub() },
      { provide: I18nService, useValue: new I18nStub() },
    ],
  });
  const fixture = TestBed.createComponent(AppTabBarComponent);
  fixture.detectChanges();
  const navSpy = spyOn(TestBed.inject(Router), 'navigate').and.resolveTo(true);
  return { fixture, component: fixture.componentInstance, navSpy };
}

describe('AppTabBarComponent', () => {
  it('renders the five customer tabs in order', () => {
    const { fixture } = setup();
    const tabs = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('ion-tab-button'),
    ).map((el) => el.getAttribute('tab'));
    expect(tabs).toEqual(['home', 'explore', 'cart', 'gift', 'profile']);
  });

  it('routes each tab to its page', () => {
    const { component, navSpy } = setup();
    const expected: Array<[Parameters<AppTabBarComponent['go']>[0], string]> = [
      ['home', '/account'],
      ['explore', '/explore'],
      ['cart', '/cart'],
      ['gift', '/gift-cards'],
      ['profile', '/settings'],
    ];
    for (const [tab, route] of expected) {
      component.go(tab);
      expect(navSpy).toHaveBeenCalledWith([route]);
    }
  });

  it('marks only the active tab', () => {
    const { fixture } = setup();
    fixture.componentRef.setInput('active', 'gift');
    fixture.detectChanges();
    const active = Array.from(
      (fixture.nativeElement as HTMLElement).querySelectorAll('ion-tab-button.m6f-tab--active'),
    ).map((el) => el.getAttribute('tab'));
    expect(active).toEqual(['gift']);
  });
});
