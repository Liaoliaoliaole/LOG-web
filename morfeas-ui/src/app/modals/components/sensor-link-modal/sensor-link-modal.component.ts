import { ModalState } from '../../services/modal-state.service';
import { ModalOptions } from '../../modal-options';
import { Component, OnInit } from '@angular/core';
import { CanbusService } from 'src/app/sdaq-management/services/canbus/canbus.service';
import { IsoStandard } from 'src/app/sdaq-management/models/iso-standard-model';

@Component({
  selector: 'app-modal-sensor-link',
  templateUrl: './sensor-link-modal.component.html'
})
export class SensorLinkModalComponent implements OnInit {
  options: ModalOptions;
  isoStandards: IsoStandard[];
  data: any; // TODO: Add type for this
  selectedIsoCode = 'Select an ISO code';
  selectedIsoStandard: IsoStandard;
  error = '';

  constructor(
    private readonly state: ModalState,
    private readonly canbusService: CanbusService
  ) {
    this.options = state.options;
  }

  ngOnInit() {
    this.data = this.state.options.data;

    if (this.data.unit) {
      this.canbusService.getIsoCodesByUnit(this.data.unit).subscribe(result => {
        if (
          this.data.configuredIsoCodes &&
          this.data.configuredIsoCodes.length > 0
        ) {
          // this.data['configuredIsoCodes'] cannot be used directly in line 37 because there's
          // another 'this' which belongs to the filter scope.
          const configuredIsoCodes = this.data.configuredIsoCodes;

          result = result.filter(
            obj => configuredIsoCodes.indexOf(obj.iso_code) < 0
          ); // remove configured ISO codes from the dropdown
        }

        this.isoStandards = result;
      });
    }
  }

  yes() {
    if (
      !isNaN(+this.selectedIsoStandard.attributes.min) &&
      !isNaN(+this.selectedIsoStandard.attributes.max)
    ) {
      if (
        +this.selectedIsoStandard.attributes.min <
        +this.selectedIsoStandard.attributes.max
      ) {
        this.state.modal.close(this.selectedIsoStandard);
      } else {
        this.error = 'Min value cannot be greater than max value!';
      }
    } else {
      this.error = 'Min or max value must be a number.';
    }
  }

  no() {
    this.state.modal.dismiss('not confirmed');
  }

  onSelectIsoCode() {
    this.selectedIsoStandard = this.isoStandards.find(
      x => x.iso_code === this.selectedIsoCode
    );
  }
}
