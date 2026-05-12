import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TicketMessagesPage } from './ticket-messages.page';

describe('ConversationsPage', () => {
  let component: TicketMessagesPage;
  let fixture: ComponentFixture<TicketMessagesPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(TicketMessagesPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
