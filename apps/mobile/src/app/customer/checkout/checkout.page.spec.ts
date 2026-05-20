import { TestBed } from '@angular/core/testing';
import { provideRouter } from '@angular/router';
import { provideHttpClient } from '@angular/common/http';
import { of } from 'rxjs';
import { CheckoutPage } from './checkout.page';
import { AddressService, SavedAddress } from '../../core/services/address.service';
import { MobileNetworkAdapter } from '../../core/http/mobile-network-adapter';
import { NetworkService } from '../../service/network.service';

function addr(id: number, over: Partial<SavedAddress> = {}): SavedAddress {
  return {
    id, label: `Home ${id}`, recipient_name: 'Jane Doe', recipient_phone: '+971500000000',
    emirate: 'Dubai', area: 'Al Marmoom', street_address: '1 Street', building_details: 'Villa 2',
    postal_code: null, country: 'AE',
    is_default: false, is_default_shipping: false, is_default_billing: false, ...over,
  };
}

class AddressServiceStub {
  listResult: SavedAddress[] = [];
  listThrows = false;
  setDefaultOk = true;
  async list(): Promise<SavedAddress[]> {
    if (this.listThrows) throw new Error('fail');
    return this.listResult;
  }
  async create() { return null; }
  async setDefault() { return this.setDefaultOk; }
}

class NetworkStub {
  get_request() { return of({ data: [] }); }
  post_request() { return of({ response_code: 200, status: 'success', data: [] }); }
}
class AdapterStub {
  get_v3() { return of({ response_code: 200, status: 'success', data: [] }); }
  post_v3() { return of({ response_code: 200, status: 'success', data: [] }); }
  patch_v3() { return of({ response_code: 200, status: 'success', data: {} }); }
}

function setup(): { component: CheckoutPage; addressSvc: AddressServiceStub } {
  const addressSvc = new AddressServiceStub();
  TestBed.configureTestingModule({
    imports: [CheckoutPage],
    providers: [
      provideRouter([]),
      provideHttpClient(),
      { provide: AddressService, useValue: addressSvc },
      { provide: NetworkService, useValue: new NetworkStub() },
      { provide: MobileNetworkAdapter, useValue: new AdapterStub() },
    ],
  });
  const fixture = TestBed.createComponent(CheckoutPage);
  return { component: fixture.componentInstance, addressSvc };
}

describe('CheckoutPage — saved-address picker (Z.2)', () => {
  it('should create', () => {
    const { component } = setup();
    expect(component).toBeTruthy();
  });

  it('loads saved addresses and pre-selects the default', async () => {
    const { component, addressSvc } = setup();
    addressSvc.listResult = [addr(1), addr(2, { is_default: true, is_default_shipping: true })];
    component.single_user.token = 'tok';
    await component.loadSavedAddresses();
    expect(component.savedAddresses.length).toBe(2);
    expect(component.selectedAddressId).toBe(2);
    expect(component.isConfirmBilling).toBeTrue();
  });

  it('falls back to the first address when none is default', async () => {
    const { component, addressSvc } = setup();
    addressSvc.listResult = [addr(10), addr(11)];
    await component.loadSavedAddresses();
    expect(component.selectedAddressId).toBe(10);
  });

  it('applies a saved address into the checkout fields', () => {
    const { component } = setup();
    component.applySavedAddress(addr(5, {
      recipient_name: 'Sara', recipient_phone: '+971555555555',
      emirate: 'Sharjah', area: 'Al Nahda', street_address: '7 Road',
      building_details: 'Flat 3', country: 'AE',
    }));
    expect(component.selectedAddressId).toBe(5);
    expect(component.update.name).toBe('Sara');
    expect(component.update.street).toBe('7 Road');
    expect(component.update.villa_number).toBe('Flat 3');
    expect(component.single_user.billing_city).toBe('Sharjah');
    expect(component.single_user.billing_street).toBe('7 Road');
    expect(component.isConfirmBilling).toBeTrue();
    expect(component.isAddressPickerOpen).toBeFalse();
  });

  it('builds a readable address summary', () => {
    const { component } = setup();
    expect(component.addressSummary(addr(1, {
      street_address: '1 Street', area: 'Al Marmoom', emirate: 'Dubai',
    }))).toBe('1 Street, Al Marmoom, Dubai');
  });

  it('openAddressPicker only opens when there are saved addresses', () => {
    const { component } = setup();
    component.savedAddresses = [];
    component.openAddressPicker();
    expect(component.isAddressPickerOpen).toBeFalse();
    component.savedAddresses = [addr(1)];
    component.openAddressPicker();
    expect(component.isAddressPickerOpen).toBeTrue();
  });

  it('survives a failed address load (manual form still usable)', async () => {
    const { component, addressSvc } = setup();
    addressSvc.listThrows = true;
    await component.loadSavedAddresses();
    expect(component.savedAddresses).toEqual([]);
    expect(component.isLoadingAddresses).toBeFalse();
  });
});
