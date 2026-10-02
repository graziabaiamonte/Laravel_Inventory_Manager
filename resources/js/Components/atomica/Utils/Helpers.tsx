import { router } from '@inertiajs/react';
import { RouteParams } from '@/types';

// The idea here is to keep the code DRY, having a wrapper for several plain functions that holds shared logic

/**
 * Gets items from the backend with pagination, sorting and filtering.
 * Used for table data fetching and filtering.
 *
 * @param routeName - The base route name (e.g. 'user', 'store')
 * @param page - Current page number for pagination
 * @param per_page - Number of items per page
 * @param sort_by - Column to sort by (prefixed with - for desc)
 * @param filter - Filter object that will be transformed by indexFilters.cleanUp
 */
const getItems = ({
    routeName,
    page,
    per_page,
    sort_by,
    filter = null,
}: {
    routeName: string;
    page?: string | number;
    per_page?: string | number;
    sort_by?: string;
    filter?: any;
}) => {
    const urlDefaultParams = route().params as RouteParams;
    // Reset to page 1 if there are filters or sort changes, otherwise use provided page or current page
    const targetPage = filter || sort_by ? 1 : page || urlDefaultParams.page;

    router.visit(
        route(routeName + '.index', {
            per_page: per_page || urlDefaultParams.per_page,
            page: targetPage,
            sort: sort_by === null || sort_by ? sort_by : (urlDefaultParams?.sort ?? null),
            filter: indexFilters.cleanUp(filter),
        }),
        {
            preserveState: true,
            preserveScroll: true,
            replace: false,
        },
    );
};

/**
 * Creates an onChange handler for form inputs.
 * Updates form state based on input id and value.
 *
 * @param setData - State setter function from useForm or useState
 * @returns Function that handles input changes
 *
 * @example
 * const inputChange = Helpers.inputChange(setData);
 * <Input onChange={inputChange} id="name" />
 */
const inputChange = (setData: any) => {
    return (e: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement>) => {
        const key = e.target.id;
        const value = e.target.value;
        setData((prev: any) => ({
            ...prev,
            [key]: value,
        }));
    };
};

/**
 * Creates a form submission handler that handles both create and update operations.
 * Determines if it should POST or PATCH based on presence of ID.
 *
 * @param form - Form object from useForm hook
 * @returns Curried function that takes routeName and optional id
 *
 * @example
 * const submit = Helpers.submitForm(form)('user', userId);
 */
const submitForm = (form: any) => {
    // NOTE: the event is optional so the submit can also be triggered programmatically,
    // e.g. from a confirmation modal (our Form component already calls it without event)
    return (routeName: string, id?: number) => (e?: React.FormEvent) => {
        if (typeof e !== 'undefined') {
            // NOTE: this will be needed only if using a standard html form
            // if using our Form component, the preventDefault() is handled from the component itself
            e.preventDefault();
        }
        const { data, post, patch } = form;

        // Check for any file uploads in the form data
        const hasFiles =
            (data.media_upload && data.media_upload.length > 0) || (data.file && data.file instanceof File);

        const method = id && !hasFiles ? patch : post;

        const url = id ? route(`${routeName}.update`, id) : route(`${routeName}.store`);

        method(url, {
            ...data,
            preserveScroll: true,
            preserveState: true,
            forceFormData: hasFiles,
        });
    };
};

/**
 * Handles bidirectional transformation of index filters between frontend and backend:
 *
 * Frontend state needs full objects for Combo components:
 * { role: { value|id: 1, label|name: 'Admin', description?: 'Administrator' }, search: 'test' }
 *
 * Backend expects specific filter keys (based on AllowedFilter::exact('roles.id')):
 * { 'roles.id': 1, search: 'test' }
 */
const indexFilters = {
    /**
     * Transforms frontend state to backend filter format.
     *
     * Frontend filter state:
     * {
     *   store: { id: 1, name: "Store 1" },
     *   date: { start: "2023-01-01" }
     * }
     *
     * After cleanUp (to backend):
     * {
     *   "stores.id": 1,
     *   "date": { start: "2023-01-01" }
     * }
     *
     * Objects with id/value props are transformed to id-based filters,
     * other objects are passed through as-is.
     */
    cleanUp: (items: object) => {
        const filters: { [key: string]: any } = {};
        for (const [key, value] of Object.entries(items)) {
            if (!value && value !== 0) continue;

            if (typeof value === 'object' && value !== null) {
                if ('id' in value || 'value' in value) {
                    const filterKey = `${key}s.id`;
                    filters[filterKey] = 'value' in value ? value.value : value.id;
                    continue;
                }
                filters[key] = value;
                continue;
            }
            filters[key] = value;
        }
        return filters;
    },

    /**
     * Transforms backend response back to frontend state format.
     *
     * Backend format:
     * {
     *   "stores.id": 1,
     *   "date": { start: "2023-01-01" }
     * }
     *
     * After setupFromResponse (to frontend):
     * {
     *   store: { id: 1, name: "Store 1" }, // Reconstructed from lookups
     *   date: { start: "2023-01-01" }  // Passed through
     * }
     */
    setupFromResponse: (applied_filters: any, lookups?: { [key: string]: any[] }) => {
        const stateFilters: { [key: string]: any } = {};

        // Process each lookup type (roles, stores, etc.)
        if (lookups)
            Object.entries(lookups).forEach(([key, items]) => {
                const pluralKey = `${key}s.id`;
                // If we have a filter like roles.id, find the corresponding full object
                if (applied_filters[pluralKey]) {
                    const itemObject = items.find((item: any) => item.value === applied_filters[pluralKey]);
                    if (itemObject) {
                        stateFilters[key] = itemObject;
                    }
                }
            });

        // Copy through other simple filters (search, etc.)
        Object.entries(applied_filters).forEach(([key, value]) => {
            if (!key.includes('.')) {
                stateFilters[key] = value;
            }
        });

        return stateFilters;
    },
};

export default {
    getItems,
    indexFilters,
    inputChange,
    submitForm,
};
