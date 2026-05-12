import { ComponentFixture, TestBed } from '@angular/core/testing';
import { StylesPage } from './styles.page';

describe('StylesPage', () => {
  let component: StylesPage;
  let fixture: ComponentFixture<StylesPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(StylesPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
