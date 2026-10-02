import { router } from '@inertiajs/react';
import { useState } from 'react';

// The idea here is to keep the code DRY, having a wrapper for several custom hooks that holds logic shared between components or pages

const useDeleteItem = ({ routeName, only }: { routeName?: string; only?: string }) => {
    const [recordToDelete, setRecordToDelete] = useState<number | number[] | null>(null);

    // const [trigger, setTrigger] = useState(false);

    // Can be triggered externally, passing all the params at once
    const Delete = (id?: string | number | Array<number> | null, routeName?: string | null) => {
        if (!id || !routeName) {
            return;
        }

        const bulk = Array.isArray(id);
        const url = bulk ? route(`${routeName}.destroy`, { id }) : route(`${routeName}.destroy`, id);

        router.visit(url, {
            preserveScroll: true,
            data: bulk
                ? {
                      ids: id,
                  }
                : {},
            preserveState: false,
            method: 'delete',
            onFinish: () => setRecordToDelete(null),
        });
    };

    const doDelete = () => {
        Delete(recordToDelete, routeName);
    };

    const changeRecordToDelete = (itemId: number | Array<number> | null) => {
        setRecordToDelete(itemId);
    };

    return { recordToDelete, changeRecordToDelete, doDelete, Delete };
};

const useForceDeleteItem = ({ routeName, only }: { routeName?: string; only?: string }) => {
    const [recordToForceDelete, setRecordToForceDelete] = useState<number | number[] | null>(null);
    // const [trigger, setTrigger] = useState(false);

    // Can be triggered externally, passing all the params at once
    const forceDelete = (id?: string | number | Array<number> | null, routeName?: string | null) => {
        if (!id || !routeName) {
            return;
        }
        const bulk = Array.isArray(id);
        const url = bulk ? route(`${routeName}.force-destroy`, { id }) : route(`${routeName}.force-destroy`, id);

        router.visit(url, {
            preserveScroll: true,
            data: bulk
                ? {
                      ids: id,
                  }
                : {},
            preserveState: false,
            method: 'delete',
            onFinish: () => setRecordToForceDelete(null),
        });
    };

    const doForceDelete = () => {
        forceDelete(recordToForceDelete, routeName);
    };

    const changeRecordToForceDelete = (itemId: number | Array<number> | null) => {
        setRecordToForceDelete(itemId);
    };

    return { recordToForceDelete, changeRecordToForceDelete, doForceDelete, forceDelete };
};

const useRestoreItem = ({ routeName, only }: { routeName?: string; only?: string }) => {
    const [recordToRestore, setRecordToRestore] = useState<number | number[] | null>(null);
    // const [trigger, setTrigger] = useState(false);

    // Can be triggered externally, passing all the params at once
    const Restore = (id?: string | number | Array<number> | null, routeName?: string | null) => {
        if (!id || !routeName) {
            return;
        }
        const bulk = Array.isArray(id);
        const url = bulk ? route(`${routeName}.restore`) : route(`${routeName}.restore`, id);

        router.visit(url, {
            preserveScroll: true,
            data: bulk
                ? {
                      ids: id,
                  }
                : {},
            preserveState: false,
            method: 'patch',
            onFinish: () => setRecordToRestore(null),
        });
    };

    const doRestore = () => {
        Restore(recordToRestore, routeName);
    };

    const changeRecordToRestore = (itemId: number | Array<number> | null) => {
        setRecordToRestore(itemId);
    };

    return { recordToRestore, changeRecordToRestore, doRestore, Restore };
};

export default {
    useDeleteItem: useDeleteItem,
    useForceDeleteItem: useForceDeleteItem,
    useRestoreItem: useRestoreItem,
};
