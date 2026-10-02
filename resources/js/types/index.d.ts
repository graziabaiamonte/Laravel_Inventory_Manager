export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User;
        roles: Array<any>;
        permissions: Array<any>;
    };
    flash: {
        success: string;
        warning?: string;
        discogsData?: any;
    };
    activeStates: Array<EnumShape>;
};

export type RequestParams = {
    page?: number | string;
    per_page: string;
    sort: string;
};

export type RouteParams = {
    page?: number;
    per_page?: number;
    sort?: string;
    filter: Filters;
    dates: Array<any> | null;
    [key: string]: any;
};

export type User = {
    id: number;
    name: string;
    last_name: string;
    email: string;
    email_verified_at: string;
    status: number;
    default_location_id: number | null;
};

export type UserType = {
    id: string;
    name: string;
    last_name?: string;
    email: string;
    password?: string;
    password_confirmation?: string;
    role: any;
    permissions: any;
    status: number;
    default_location_id: number | null;
    locations: Array<any>;
};

export type Location = {
    id: number;
    area_id?: number;
    areas?: Array<any>;
    default_area_id: number | null;
    name: string;
    type: string;
    status: number;
    default_wholesaleout_location?: boolean;
};

export type EnumShape = {
    label: string;
    value: string;
    description: string;
};

export type Format = {
    id: number;
    name: string;
    status: number;
};

export type Artist = {
    id: number;
    name: string;
    status: number;
};

export type Record = {
    id: number;
    rr_uid: string;
    barcode: string;
    cat_number: string;
    release_id: string;
    type: string;
    title: string;
    retail_price: number;
    wholesale_price: number;
    purchase_price: number;
    disk_status: number;
    disk_status_name?: string;
    cover_status: number;
    cover_status_name?: string;
    for_sale_on_discogs: number;
    discogs_id: string;
    description: string;
    comments: string;
    location_text?: string | null;
    format_id: number | null;
    artist_id: number | null;
    label_id: number | null;
    format: Format | null;
    artist: Artist | null;
    label: Label | null;
    stock?: Stock[];
    total_stocks?: number;
    total_warehouse_stocks?: number;
    // available_quantity?: number;
    media: Array<FileMedia>;
    media_upload: Array<File>;
    discogs_image_url?: string;
};

export type SaleRecord = {
    id: number;
    //sale_id: number;
    record_id: number;
    stock_id: number;
    quantity: number;
    price?: string | number;
    discount: number;
    vat?: string | number;
    total_price?: string | number;
    parent_record?: Record;
    area_location_ids?: Array<any>;
    stock?: Array<{ id: number; location_name: string; area_location_ids?: Array<{ id: number }> }>;
};

export type SaleRecord = {
    id: number;
    //sale_id: number;
    record_id: number;
    stock_id: number;
    quantity: number;
    price?: string | number;
    discount: number;
    total_price?: string | number;
    parent_record?: Record;
    area_location_ids?: Array<any>;
    stock?: Array<{ id: number; location_name: string; area_location_ids?: Array<{ id: number }> }>;
};

export type RecordImport = {
    id: number;
    draft: boolean;
    records?: Array<ImportRecordItem>;
    created_at?: string;
    updated_at?: string;
};

export type Label = {
    id: number;
    name: string;
    status: number;
};

export type Customer = {
    id: number;
    name: string;
    last_name?: string;
    address: string;
    phone?: string;
    email: string;
    status: number;
};

export type Supplier = {
    id: number;
    name: string;
    address: string;
    phone: string;
    email: string;
    status: number;
};

export type Sale = {
    id: number;
    user_id: number;
    user?: User;
    location_id: number;
    location?: Location;
    remote_customer_id: string;
    remote_customer_name: string;
    type: string;
    amount: number;
    amount_fromatted?: string;
    date: string;
    description: string;
    records: SaleRecord[];
};

export type Stock = {
    id: number;
    record_id: number;
    area_id: number;
    quantity: number;
    description: string;
    order_column: number;
    area_location_ids?: Array<any>;
    area?: {
        id: number;
        name: string;
    };
};

export interface AreaQuantity {
    area_id: number;
    area_name: string | null;
    quantity: number;
}

export type Area = {
    id: number;
    name: string;
    status: number;
};

export interface WholesaleInRecord {
    id?: number;
    wholesale_in_id?: number;
    record_id: number;
    quantity: number;
    unit_price: string | number;
    discount: number;
    total_price: string | number;
    vat: string;
    parent_record?: Record;

    type?: string;
    cat_number?: string;
    title?: string;
    artist?: string;
    format?: string;
    label?: string;
    barcode?: string;
    retail_price?: string | number | null | undefined;
    wholesale_price?: string | number | null | undefined;
    purchase_price?: string | number | null | undefined;
    area_quantities?: AreaQuantity[];
    condition_disk?: string;
    condition_cover?: string;
    release_id?: string;
    for_sale_on_discogs?: boolean;
    discogs_id?: string;
}

export interface ImportRecordItem {
    id: number;
    draft: number;
    type?: string;
    unit_price?: number;
    discount?: number;
    cat_number?: string;
    title?: string;
    release_id?: string | number | null | undefined; // Add release_id field
    artist?: { id: number; name: string }; // Objects for main fields
    format?: { id: number; name: string }; // Objects for main fields
    label?: { id: number; name: string }; // Objects for main fields
    artist_name?: string; // String fallbacks
    format_name?: string; // String fallbacks
    label_name?: string; // String fallbacks
    record_id?: number;
    retail_price?: string | number | null | undefined;
    wholesale_price?: string | number | null | undefined;
    purchase_price?: string | number | null | undefined;
    delete?: boolean;
    soft_delete?: boolean;
    d_delete?: boolean;
    [key: string]: any; // for other properties
}

export interface WholesaleIn {
    id: number;
    supplier_id: number;
    area_id: number;
    total_price: number;
    doc_num: string;
    description: string | null;
    status: number;
    file: string | null;
    supplier?: {
        id: number;
        name: string;
    };
    area?: {
        id: number;
        name: string;
    };
    records?: WholesaleInRecord[];
}

export interface WholesaleOutRecord {
    id?: number;
    wholesale_out_id?: number;
    record_id: number;
    stock_id: number;
    quantity: number;
    unit_price: string | number;
    discount: number;
    total_price: string | number;
    vat: string | number;
    parent_record?: Record;

    cat_number?: string;
    barcode?: string;
    artist_name?: string;
    title?: string;
    format_name?: string;
    label_name?: string;
    // available_quantity?: number;
    available_quantity?: number; // Total quantity available across all areas/stocks
    warehouse_stock_quantity?: number; // Total quantity available in warehouse areas only
    shipped_quantity?: number; // New field for shipped quantity

    stock?: Stock[]; // Array of stock objects

    // Area quantities for UI components (future area repeater)
    area_quantities?: AreaQuantity[];
}

export interface BackOrderRecord {
    id?: number;
    backorder_id?: number;
    record_id: number;
    stock_id: number;
    quantity: number;
    unit_price: string | number;
    discount: number;
    total_price: string | number;
    vat: string | number;
    parent_record?: Record;

    cat_number?: string;
    barcode?: string;
    artist_name?: string;
    title?: string;
    format_name?: string;
    label_name?: string;
    available_quantity?: number; // Total quantity available across all areas/stocks
    shipped_quantity?: number; // Calculated: original quantity - current backorder quantity
    backordered_quantity?: number; // Calculated: current backorder quantity

    // Area quantities for UI components (future area repeater)
    area_quantities?: AreaQuantity[];
}

export interface WholesaleOutLabelDiscount {
    label_id: number;
    label_name: string;
    discount: number;
}

export interface WholesaleOut {
    id: number;
    customer_id: number;
    area_id: number;
    total_price: string | number;
    doc_num: string;
    description: string | null;
    status: number;
    file: string | null;
    created_at?: string;
    updated_at?: string;
    customer?: Customer;
    area?: Area;
    records?: WholesaleOutRecord[];
    label_discounts?: WholesaleOutLabelDiscount[];
    backorders?: Backorder[]; // Added backorders field
}

export interface Backorder {
    id: number;
    wholesale_out_id: number;
    status: number;
    records?: WholesaleOutRecord[];
    customer_name?: string;
    whole_sale_out_id?: number;
}

type MapByMonth = {
    [key: number]: Array<number>;
};

type MapByYear = {
    [key: number]: MapByMonth;
};
