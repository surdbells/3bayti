import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorComplianceComponent } from './vendor-compliance.component';

describe('VendorComplianceComponent', () => {
  let component: VendorComplianceComponent;
  let fixture: ComponentFixture<VendorComplianceComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorComplianceComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorComplianceComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
