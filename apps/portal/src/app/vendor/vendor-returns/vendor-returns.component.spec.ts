import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorReturnsComponent } from './vendor-returns.component';

describe('VendorReturnsComponent', () => {
  let component: VendorReturnsComponent;
  let fixture: ComponentFixture<VendorReturnsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorReturnsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorReturnsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
