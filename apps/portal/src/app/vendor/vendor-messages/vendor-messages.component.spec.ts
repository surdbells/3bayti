import { ComponentFixture, TestBed } from '@angular/core/testing';

import { VendorMessagesComponent } from './vendor-messages.component';

describe('VendorMessagesComponent', () => {
  let component: VendorMessagesComponent;
  let fixture: ComponentFixture<VendorMessagesComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [VendorMessagesComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(VendorMessagesComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
