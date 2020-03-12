import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ModalState } from './services/modal-state.service';
import { ModalService } from './services/modal.service';
import { ConfirmModalComponent } from './components/confirm-modal.component';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { InformationModalComponent } from './components/information-modal.component';
import { SensorLinkModalComponent } from './components/sensor-link-modal/sensor-link-modal.component';
import { FormsModule } from '@angular/forms';
import { NgSelectModule } from '@ng-select/ng-select';
import { ConfigModalComponent } from './components/config-modal/config-modal.component';
import { LogModalComponent } from './components/log-modal/log-modal.component';

// Add new modal components to this array for correct configuration
const modals = [
  ConfirmModalComponent,
  InformationModalComponent,
  SensorLinkModalComponent,
  ConfigModalComponent,
  LogModalComponent
];

@NgModule({
  imports: [
    CommonModule,
    NgbModule,
    FormsModule,
    NgSelectModule
  ],
  declarations: modals,
  providers: [ModalState, ModalService],
  exports: modals,
  entryComponents: modals

})
export class ModalsModule { }
