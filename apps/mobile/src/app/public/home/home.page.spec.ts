import { ComponentFixture, TestBed } from '@angular/core/testing';
import { By } from '@angular/platform-browser';
import { HomePage } from './home.page';
import { AxAinConciergeSheetComponent } from '../../shared/ax-mobile/ain-concierge-sheet';

describe('HomePage', () => {
  let component: HomePage;
  let fixture: ComponentFixture<HomePage>;

  beforeEach(() => {
    fixture = TestBed.createComponent(HomePage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('hides Search by photo from the guest Ain sheet (iOS camera crash on old shells)', () => {
    const sheet = fixture.debugElement.query(By.directive(AxAinConciergeSheetComponent))
      .componentInstance as AxAinConciergeSheetComponent;
    expect(sheet.tools.map((t) => t.key)).toEqual(['ask', 'gift', 'style']);
  });
});
