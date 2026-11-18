import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { IonicModule } from '@ionic/angular';
import { FormsModule } from '@angular/forms';
import { MealPlanPageRoutingModule } from './meal-plan-routing.module';
import { MealPlanPage } from './meal-plan.page';
import { StateComponent } from '../shared/state/state.component';
@NgModule({ imports: [StateComponent, CommonModule, FormsModule, IonicModule, MealPlanPageRoutingModule], declarations: [MealPlanPage] })
export class MealPlanPageModule {}
