import { usePage } from '@inertiajs/react';
import { PageProps } from '@/types';

export default function UseHasRoleOrPermissions({ permissions = [''] }: { permissions: Array<string> }) {
    const { auth } = usePage<PageProps>().props;
    const rolesAndPermissions = [...auth.roles, ...auth.permissions];
    return rolesAndPermissions.some(permission => {
        if (permissions.includes(permission)) {
            return true;
        }
    });
}
