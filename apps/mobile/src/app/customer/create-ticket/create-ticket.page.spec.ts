import { ComponentFixture, TestBed } from '@angular/core/testing';
import { CreateTicketPage } from './create-ticket.page';

describe('CreateTicketPage', () => {
  let component: CreateTicketPage;
  let fixture: ComponentFixture<CreateTicketPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(CreateTicketPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
