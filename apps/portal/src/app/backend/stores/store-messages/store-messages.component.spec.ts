import { ComponentFixture, TestBed } from '@angular/core/testing';

import { StoreMessagesComponent } from './store-messages.component';

describe('StoreMessagesComponent', () => {
  let component: StoreMessagesComponent;
  let fixture: ComponentFixture<StoreMessagesComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StoreMessagesComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(StoreMessagesComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
