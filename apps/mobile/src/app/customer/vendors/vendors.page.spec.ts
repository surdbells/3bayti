import { ComponentFixture, TestBed } from '@angular/core/testing';
import { VendorsPage } from './vendors.page';

describe('VendorsPage', () => {
  let component: VendorsPage;
  let fixture: ComponentFixture<VendorsPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(VendorsPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
