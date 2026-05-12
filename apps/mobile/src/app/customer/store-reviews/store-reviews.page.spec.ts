import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StoreReviewsPage } from './store-reviews.page';

describe('StoreReviewsPage', () => {
  let component: StoreReviewsPage;
  let fixture: ComponentFixture<StoreReviewsPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(StoreReviewsPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
