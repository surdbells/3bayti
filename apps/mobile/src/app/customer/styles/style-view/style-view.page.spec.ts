import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StyleViewPage } from './style-view.page';

describe('StyleViewPage', () => {
  let component: StyleViewPage;
  let fixture: ComponentFixture<StyleViewPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(StyleViewPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
