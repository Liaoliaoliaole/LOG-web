import { NgModule } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ModalState } from './services/modal-state.service';
import { ModalService } from './services/modal.service';
import { ConfirmModalComponent } from './components/confirm-modal.component';
import { NgbModule } from '@ng-bootstrap/ng-bootstrap';
import { InformationModalComponent } from './components/information-modal.component';

// Add new modal components to this array for correct configuration
const modals = [
  ConfirmModalComponent,
  InformationModalComponent
];

@NgModule({
  imports: [
    CommonModule,
    NgbModule
  ],
  declarations: modals,
  providers: [ModalState, ModalService],
  exports: modals,
  entryComponents: modals

})
export class ModalsModule { }
