import { ComponentFixture, TestBed } from '@angular/core/testing';
import { TicketListPage } from './ticket-list.page';

describe('TicketListPage', () => {
  let component: TicketListPage;
  let fixture: ComponentFixture<TicketListPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(TicketListPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
