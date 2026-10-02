import { router } from '@inertiajs/react';

export type methodTypes = 'post' | 'POST' | 'put' | 'PUT' | 'PATCH' | 'patch';
const defaultOptions = {
    preserveState: true,
    preserveScroll: true,
    forceFormData: true,
};
export default function useUploadRouter({
    url,
    data,
    method = 'post',
    options = defaultOptions,
}: {
    url: string;
    data: any;
    method?: methodTypes;
    options?: any;
}) {
    return () => {
        router.post(url, { ...data, _method: method }, options);
    };
}
