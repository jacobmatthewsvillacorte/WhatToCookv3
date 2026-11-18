import { IonicModule } from '@ionic/angular';
import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormsModule } from '@angular/forms';
import { PantryPage } from './pantry.page';

import { PantryPageRoutingModule } from './pantry-routing.module';

@NgModule({
  imports: [
    IonicModule,
    CommonModule,
    FormsModule,
    PantryPageRoutingModule
  ],
  declarations: [PantryPage]
})
export class PantryPageModule {}
