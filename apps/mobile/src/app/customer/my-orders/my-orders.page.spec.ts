import { ComponentFixture, TestBed } from '@angular/core/testing';
import { MyOrdersPage } from './my-orders.page';

describe('MyOrdersPage', () => {
  let component: MyOrdersPage;
  let fixture: ComponentFixture<MyOrdersPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(MyOrdersPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
