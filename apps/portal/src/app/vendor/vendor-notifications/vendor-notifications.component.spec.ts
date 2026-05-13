import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorNotificationsComponent } from './vendor-notifications.component';

describe('VendorSettingsComponent', () => {
  let component: VendorNotificationsComponent;
  let fixture: ComponentFixture<VendorNotificationsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorNotificationsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorNotificationsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
