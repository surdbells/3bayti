import { ComponentFixture, TestBed } from '@angular/core/testing';
import { NewArrivalsPage } from './new-arrivals.page';

describe('SearchPage', () => {
  let component: NewArrivalsPage;
  let fixture: ComponentFixture<NewArrivalsPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(NewArrivalsPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
