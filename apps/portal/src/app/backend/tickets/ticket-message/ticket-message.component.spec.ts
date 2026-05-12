import { ComponentFixture, TestBed } from '@angular/core/testing';

import { TicketMessageComponent } from './ticket-message.component';

describe('TicketMessageComponent', () => {
  let component: TicketMessageComponent;
  let fixture: ComponentFixture<TicketMessageComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [TicketMessageComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(TicketMessageComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
