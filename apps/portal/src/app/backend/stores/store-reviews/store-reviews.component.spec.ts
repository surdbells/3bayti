import { ComponentFixture, TestBed } from '@angular/core/testing';

import { StoreReviewsComponent } from './store-reviews.component';

describe('StoreReviewsComponent', () => {
  let component: StoreReviewsComponent;
  let fixture: ComponentFixture<StoreReviewsComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [StoreReviewsComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(StoreReviewsComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
