import { ComponentFixture, TestBed } from '@angular/core/testing';
import { FailedPage } from './failed.page';

describe('FailedPage', () => {
  let component: FailedPage;
  let fixture: ComponentFixture<FailedPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(FailedPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
