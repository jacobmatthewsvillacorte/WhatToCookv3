import { IonicModule } from '@ionic/angular';
import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PantryPage } from './pantry.page';

import { PantryPageRoutingModule } from './pantry-routing.module';
import { StateComponent } from '../shared/state/state.component';

@NgModule({
  imports: [StateComponent, 
    IonicModule,
    CommonModule,
    FormsModule,
    PantryPageRoutingModule
  ],
  declarations: [PantryPage]
})
export class PantryPageModule {}
