import { ComponentFixture, TestBed } from '@angular/core/testing';
import { VerticanPage } from './vertican.page';

describe('VerticanPage', () => {
  let component: VerticanPage;
  let fixture: ComponentFixture<VerticanPage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(VerticanPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
