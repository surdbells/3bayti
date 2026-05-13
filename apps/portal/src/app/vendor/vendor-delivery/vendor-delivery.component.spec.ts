import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorDeliveryComponent } from './vendor-delivery.component';

describe('VendorDeliveryComponent', () => {
  let component: VendorDeliveryComponent;
  let fixture: ComponentFixture<VendorDeliveryComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorDeliveryComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorDeliveryComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
