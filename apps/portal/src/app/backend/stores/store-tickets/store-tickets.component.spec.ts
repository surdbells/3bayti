import { ComponentFixture, TestBed } from '@angular/core/testing';

import { StoreTicketsComponent } from './store-tickets.component';

describe('StoreTicketsComponent', () => {
  let component: StoreTicketsComponent;
  let fixture: ComponentFixture<StoreTicketsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StoreTicketsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(StoreTicketsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
