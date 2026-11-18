import { IonicModule } from '@ionic/angular';
import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { RecipesPage } from './recipes.page';

import { RecipesPageRoutingModule } from './recipes-routing.module';
import { StateComponent } from '../shared/state/state.component';

@NgModule({
  imports: [StateComponent, 
    IonicModule,
    CommonModule,
    FormsModule,
    RecipesPageRoutingModule
  ],
  declarations: [RecipesPage]
})
export class RecipesPageModule {}
