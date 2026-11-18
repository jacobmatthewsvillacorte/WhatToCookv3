import { ComponentFixture, TestBed } from '@angular/core/testing';
import { HttpClientTestingModule } from '@angular/common/http/testing';
import { FormsModule } from '@angular/forms';
import { IonicModule } from '@ionic/angular';
import { RouterTestingModule } from '@angular/router/testing';


import { RecipesPage } from './recipes.page';
import { of } from 'rxjs';
import { ApiService, Family } from '../services/api.service';
import { AuthService } from '../services/auth.service';
import { HouseholdContextService } from '../services/household-context.service';
import { StateComponent } from '../shared/state/state.component';

describe('RecipesPage', () => {
  let component: RecipesPage;
  let fixture: ComponentFixture<RecipesPage>;

  beforeEach(async () => {
    await TestBed.configureTestingModule({
      declarations: [RecipesPage],
      imports: [StateComponent, IonicModule.forRoot(), FormsModule, HttpClientTestingModule, RouterTestingModule]
    }).compileComponents();

    fixture = TestBed.createComponent(RecipesPage);
    component = fixture.componentInstance;
    fixture.detectChanges();
  });

  it('should create', () => {
    expect(component).toBeTruthy();
  });

  it('keeps a personal recipe scope local instead of selecting global personal context', () => {
    const family: Family = { id: 9, name: 'Santos', owner_id: 1 };
    const api = jasmine.createSpyObj<ApiService>('ApiService', ['searchRecipes']);
    api.searchRecipes.and.returnValue(of({ data: [], current_page: 1, last_page: 1, total: 0 }));
    Object.defineProperty(api, 'hasToken', { value: true });
    const context = jasmine.createSpyObj<HouseholdContextService>('HouseholdContextService', ['select']);
    const page = new RecipesPage(api, context, { user: { id: 1 } } as AuthService, jasmine.createSpyObj('Router', ['navigate']), jasmine.createSpyObj('AlertController', ['create']));
    page.families = [family];
    page.cookingScope = 'personal';

    page.changeCookingScope();

    expect(context.select).not.toHaveBeenCalled();
    expect(api.searchRecipes).toHaveBeenCalledWith('', undefined, jasmine.objectContaining({ page: 1 }));
  });

  it('requests the next page when more recipes are available', () => {
    const api = jasmine.createSpyObj<ApiService>('ApiService', ['searchRecipes']);
    api.searchRecipes.and.returnValue(of({ data: [], current_page: 2, last_page: 3, total: 30 }));
    const page = new RecipesPage(api, jasmine.createSpyObj('HouseholdContextService', ['refresh']), { user: { id: 1 } } as AuthService, jasmine.createSpyObj('Router', ['navigate']), jasmine.createSpyObj('AlertController', ['create']));
    page.currentPage = 1; page.lastPage = 3;
    page.loadMore();
    expect(api.searchRecipes).toHaveBeenCalledWith('', undefined, jasmine.objectContaining({ page: 2 }));
  });
});
