import { Component, Input, Output, EventEmitter } from '@angular/core';

export class TableColumn {
  id: string;
  displayName: string;
  isVisible: boolean;

  constructor(id: string, displayName: string) {
    this.id = id;
    this.displayName = displayName;
    this.isVisible = true;
  }
}

@Component({
  selector: 'app-device-table-sidebar',
  templateUrl: './device-table-sidebar.component.html',
  styleUrls: ['./device-table-sidebar.component.scss']
})
export class DeviceTableSidebarComponent {

  @Input() columns: TableColumn[];
  @Input() showUnsaved: boolean;
  @Output() columnToggle = new EventEmitter<string>();
  @Output() saveConfigs = new EventEmitter();

  @Output() linkedToggle = new EventEmitter<boolean>();
  @Output() unlinkedToggle = new EventEmitter<boolean>();

  @Output() can1Toggle = new EventEmitter<boolean>();
  @Output() can2Toggle = new EventEmitter<boolean>();

  showOptions = true;
  showLinked = true;
  showUnlinked = true;

  showCan1 = true;
  showCan2 = true;

  constructor() { }

  onColumnClick(column: TableColumn): void {
    column.isVisible = !column.isVisible;
    this.columnToggle.next(column.id);
  }

  onLinkedClick(): void {
    this.showLinked = !this.showLinked;
    this.linkedToggle.next(this.showLinked);
  }

  onUnlinkedClick(): void {
    this.showUnlinked = !this.showUnlinked;
    this.unlinkedToggle.next(this.showUnlinked);
  }

  onCan1Click(): void {
    this.showCan1 = !this.showCan1;
    this.can1Toggle.next(this.showCan1);
  }

  onCan2Click(): void {
    this.showCan2 = !this.showCan2;
    this.can2Toggle.next(this.showCan2);
  }

  toggleShowOptions() {
    this.showOptions = !this.showOptions;
  }

  onSaveConfigs() {
    this.saveConfigs.emit();
  }
}
