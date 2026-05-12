import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorTaxComponent } from './vendor-tax.component';

describe('VendorTaxComponent', () => {
  let component: VendorTaxComponent;
  let fixture: ComponentFixture<VendorTaxComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorTaxComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorTaxComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
