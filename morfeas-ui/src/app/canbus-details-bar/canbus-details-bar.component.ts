import { Component, OnInit, Input, ChangeDetectionStrategy } from '@angular/core';
import { Logstat } from '../sdaq-management/models/can-model';

@Component({
  selector: 'app-canbus-details-bar',
  templateUrl: './canbus-details-bar.component.html',
  styleUrls: ['./canbus-details-bar.component.scss'],
  changeDetection: ChangeDetectionStrategy.OnPush
})
export class CanbusDetailsBarComponent {
  @Input() logstats: Logstat[];

  constructor() { }

}
