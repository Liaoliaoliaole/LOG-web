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

  showOptions = true;
  showLinked = true;
  showUnlinked = true;

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

  toggleShowOptions() {
    this.showOptions = !this.showOptions;
  }

  onSaveConfigs() {
    this.saveConfigs.emit();
  }
}
