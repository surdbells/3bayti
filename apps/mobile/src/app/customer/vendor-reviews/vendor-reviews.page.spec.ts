import { ComponentFixture, TestBed } from '@angular/core/testing';
import { VendorReviewsPage } from './vendor-reviews.page';

describe('VendorReviewsPage', () => {
  let component: VendorReviewsPage;
  let fixture: ComponentFixture<VendorReviewsPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(VendorReviewsPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
