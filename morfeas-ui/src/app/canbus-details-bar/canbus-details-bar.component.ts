import { Component, OnInit, Input } from '@angular/core';
import { CanbusService } from '../sdaq-management/services/canbus/canbus.service';
import { CanBusModel } from '../sdaq-management/models/can-model';

@Component({
  selector: 'app-canbus-details-bar',
  templateUrl: './canbus-details-bar.component.html',
  styleUrls: ['./canbus-details-bar.component.scss']
})
export class CanbusDetailsBarComponent implements OnInit {
  @Input() canBusDetails: CanBusModel[];

  constructor() { }

  ngOnInit() { }
}
