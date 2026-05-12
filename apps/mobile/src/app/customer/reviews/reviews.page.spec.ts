import { ComponentFixture, TestBed } from '@angular/core/testing';
import { ReviewsPage } from './reviews.page';

describe('AddressesPage', () => {
  let component: ReviewsPage;
  let fixture: ComponentFixture<ReviewsPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(ReviewsPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
