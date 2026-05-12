import { ComponentFixture, TestBed } from '@angular/core/testing';

import { ProcessingComponent } from './processing.component';

describe('ProductSalesComponent', () => {
  let component: ProcessingComponent;
  let fixture: ComponentFixture<ProcessingComponent>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      imports: [ProcessingComponent]
    })
    .compileComponents();

    fixture = TestBed.createComponent(ProcessingComponent);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });
});
