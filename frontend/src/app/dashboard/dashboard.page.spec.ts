import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { IonicModule } from '@ionic/angular';
import { FormsModule } from '@angular/forms';


import { DashboardPage } from './dashboard.page';
import { of } from 'rxjs';
import { ApiService, Family } from '../services/api.service';
import { HouseholdContextService } from '../services/household-context.service';
import { AuthService } from '../services/auth.service';

describe('DashboardPage', () => {
  let component: DashboardPage;
  let fixture: ComponentFixture<DashboardPage>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [DashboardPage],
      imports: [IonicModule.forRoot(), FormsModule, HttpClientTestingModule]
    }).compileComponents();

    fixture = TestBed.createComponent(DashboardPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('loads personal stock alongside every authorized household inventory', () => {
    const family: Family = { id: 7, name: 'Santos', owner_id: 1 };
    const secondFamily: Family = { id: 8, name: 'Reyes', owner_id: 2 };
    const api = jasmine.createSpyObj<ApiService>('ApiService', ['pantry', 'recommendations']);
    Object.defineProperty(api, 'hasToken', { value: true });
    api.pantry.and.returnValue(of([{ id: 1, name: 'Salt' }, { id: 2, name: 'Rice', family_id: 7 }, { id: 3, name: 'Eggs', family_id: 8 }]));
    api.recommendations.and.returnValue(of({ recommendations: [] }));
    const context = jasmine.createSpyObj<HouseholdContextService>('HouseholdContextService', ['refresh']);
    context.refresh.and.returnValue(of({ userId: 1, families: [family, secondFamily], activeFamily: family }));
    const page = new DashboardPage(api, context, jasmine.createSpyObj('Router', ['navigate']), jasmine.createSpyObj('AlertController', ['create']), { user: { id: 1 } } as AuthService);

    page.loadDashboardData();

    expect(api.pantry).toHaveBeenCalledWith();
    expect(page.personalItems.map(item => item.name)).toEqual(['Salt']);
    expect(page.householdItems.map(item => item.name)).toEqual(['Rice', 'Eggs']);
    expect(page.families.map(item => item.name)).toEqual(['Santos', 'Reyes']);
    expect(page.sharedItems(family.id).map(item => item.name)).toEqual(['Rice']);
    expect(page.sharedItems(secondFamily.id).map(item => item.name)).toEqual(['Eggs']);
  });

  it('switches the recommendation scope without combining pantry boundaries', () => {
    const family: Family = { id: 7, name: 'Santos', owner_id: 1 };
    const api = jasmine.createSpyObj<ApiService>('ApiService', ['pantry', 'recommendations']);
    Object.defineProperty(api, 'hasToken', { value: true });
    api.pantry.and.returnValue(of([{ id: 1, name: 'Salt' }, { id: 2, name: 'Rice', family_id: 7 }]));
    api.recommendations.and.returnValue(of({ recommendations: [] }));
    const context = jasmine.createSpyObj<HouseholdContextService>('HouseholdContextService', ['refresh', 'select']);
    context.refresh.and.returnValues(
      of({ userId: 1, families: [family], activeFamily: family }),
      of({ userId: 1, families: [family], activeFamily: null }),
    );
    const page = new DashboardPage(api, context, jasmine.createSpyObj('Router', ['navigate']), jasmine.createSpyObj('AlertController', ['create']), { user: { id: 1 } } as AuthService);

    page.loadDashboardData();
    page.householdScope = 'personal';
    page.changeHousehold();

    expect(context.select).toHaveBeenCalledWith(1, null);
    expect(page.personalItems.map(item => item.name)).toEqual(['Salt']);
    expect(page.householdItems.map(item => item.name)).toEqual(['Rice']);
  });
});
