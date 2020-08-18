export interface MorfeasConfigModel {
    app_name: string;
    components: any;
    canbus_if_options: string[];
}

export interface MorfeasConfigComponent {
    CANBUS_IF?: string;
    DEV_NAME?: string;
    IPv4_ADDR?: string;
}
