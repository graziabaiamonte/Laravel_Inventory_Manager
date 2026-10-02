import React, { useState, PropsWithChildren, ReactNode, useEffect } from 'react';
import { usePage } from '@inertiajs/react';
import { PageProps, Location as LocationType } from '@/types';
import SidebarLayout from '@/Components/atomica/SidebarLayout';
import { AcademicCapIcon, HashtagIcon } from '@heroicons/react/24/solid';
import Toast from '@/Components/atomica/Alerts/Toast';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';
import {
    Book,
    BookOpen,
    CalendarDays,
    Clipboard,
    Building2,
    FileCheck,
    GraduationCap,
    Hash,
    HeartPulse,
    Home,
    Image,
    LayoutTemplate,
    LucideHome,
    MenuSquare,
    MessageCircleIcon,
    Newspaper,
    ScrollText,
    Users,
    Box,
    ShieldCheck,
    Store as StoreIcon,
    MapPin,
    LetterText,
    Mic,
    Disc2,
    Users2,
    Truck,
    Music,
    DollarSign,
    PackageCheck,
    Settings,
    Undo2,
} from 'lucide-react';

const getLocationMenuItems = (auth: { user: any }) => {
    if (UseHasRoleOrPermissions({ permissions: ['admin', 'manager', 'all'] })) {
        return [
            {
                name: 'Locations',
                href: route('location.index'),
                icon: StoreIcon,
                current: route().current('location.*'),
            },
        ];
    } else {
        // Get user's stores from auth user
        const userLocations: Array<LocationType> = auth.user.locations || [];
        // console.log(auth.user);

        // Create menu entry for each store the user has access to
        return userLocations.map((location: LocationType) => ({
            name: `Location ${location.name}`,
            href: route('location.edit', location.id),
            icon: StoreIcon,
            current: route().current('location.edit', location.id),
        }));
    }
};

export default function Authenticated({
    header,
    children,
    title,
    description,
    onSearch = null,
    search,
}: PropsWithChildren<{
    header?: ReactNode;
    title?: string;
    description?: string;
    onSearch?: ((value: string) => void) | null;
    search?: string;
}>) {
    // Flash messages, validation errors and auth data shared by the backend
    const { flash, errors, auth } = usePage<PageProps>().props;
    const [success, setSuccess] = useState(false);
    const [error, setError] = useState(false);
    const [warning, setWarning] = useState(false);

    const navigation = [
        {
            name: 'Dashboard',
            href: '/dashboard',
            icon: LucideHome,
            current: route().current('dashboard'),
        },
        {
            name: 'Utenti',
            href: route('user.index'),
            icon: Users,
            current: route().current('user.*'),
            hide: !UseHasRoleOrPermissions({ permissions: ['admin', 'all', 'manage_users'] }),
        },
        ...getLocationMenuItems(auth),
        {
            name: 'Aree',
            href: route('area.index'),
            icon: MapPin,
            current: route().current('area.*'),
        },
        {
            name: 'Records',
            href: route('record.index'),
            icon: Music,
            current: route().current('record.*'),
            children: [
                {
                    name: 'Riepilogo',
                    href: route('record.index'),
                    current: route().current('record.index'),
                },
                {
                    name: 'Crea Record',
                    href: route('record.create'),
                    current: route().current('record.create'),
                },
                {
                    name: 'Importa Record',
                    href: route('records-import.create'),
                    current: route().current('records-import.create'),
                },
            ],
        },
        {
            name: 'Vendite',
            href: route('sale.index', {
                filter: {
                    date: {
                        startDate: new Date().toISOString().split('T')[0],
                        endDate: new Date().toISOString().split('T')[0],
                    },
                },
            }),
            icon: DollarSign,
            current: route().current('sale.*'),
            children: [
                {
                    name: 'Riepilogo',
                    href: route('sale.index', {
                        filter: {
                            date: {
                                startDate: new Date().toISOString().split('T')[0],
                                endDate: new Date().toISOString().split('T')[0],
                            },
                        },
                    }),
                    current: route().current('sale.index'),
                },
                {
                    name: 'Aggiungi Vendita',
                    href: route('sale.create'),
                    current: route().current('sale.create'),
                },
            ],
        },
        {
            name: 'Carico',
            href: route('wholesale-in.index'),
            icon: PackageCheck,
            current: route().current('wholesale-in.*'),
            children: [
                {
                    name: 'Riepilogo',
                    href: route('wholesale-in.index'),
                    current: route().current('wholesale-in.index'),
                },
                {
                    name: 'Aggiungi Carico',
                    href: route('wholesale-in.create'),
                    current: route().current('wholesale-in.create'),
                },
            ],
        },
        {
            name: 'Scarico',
            href: route('wholesale-out.index'),
            icon: Truck,
            current: route().current('wholesale-out.*'),
            children: [
                {
                    name: 'Riepilogo',
                    href: route('wholesale-out.index'),
                    current: route().current('wholesale-out.index'),
                },
                {
                    name: 'Aggiungi Scarico',
                    href: route('wholesale-out.create'),
                    current: route().current('wholesale-out.create'),
                },
                {
                    name: 'Backorder',
                    href: route('backorder.index'),
                    icon: Undo2,
                    current: route().current('backorder.*'),
                },
            ],
        },

        {
            name: 'Gestione Contenuti',
            href: route('content.index'),
            icon: Settings,
            current: route().current('content.*'),
            children: [
                {
                    name: 'Formati',
                    href: route('format.index'),
                    current: route().current('format.index'),
                },
                {
                    name: 'Artisti',
                    href: route('artist.index'),
                    current: route().current('artist.index'),
                },
                {
                    name: 'Etichette',
                    href: route('label.index'),
                    current: route().current('label.index'),
                },
                {
                    name: 'Clienti',
                    href: route('customer.index'),
                    current: route().current('customer.index'),
                },
                {
                    name: 'Fornitori',
                    href: route('supplier.index'),
                    current: route().current('supplier.index'),
                },
            ],
        },
    ];

    const dismissAll = () => {
        setSuccess(false);
        setError(false);
        setWarning(false);
    };

    useEffect(() => {
        if (flash.success) {
            dismissAll();
            setSuccess(true);
        }
    }, [flash.success]);

    useEffect(() => {
        if (Object.keys(errors).length > 0) {
            dismissAll();
            setError(true);
        }
    }, [errors]);

    useEffect(() => {
        if (flash.warning) {
            dismissAll();
            setWarning(true);
        }
    }, [flash.warning]);

    return (
        <SidebarLayout
            onSearch={onSearch}
            search={search}
            user={auth.user}
            title={title}
            description={description}
            items={navigation}>
            <Toast
                show={success}
                variant='success'
                message={flash.success ?? 'Successo'}
                onClose={() => setSuccess(false)}
            />
            <Toast
                show={error}
                variant='error'
                message={
                    '<ul>' +
                    Object.values(errors)
                        .map(e => `<li>${e}</li>`)
                        .join('') +
                    '</ul>'
                }
                onClose={() => setError(false)}
            />
            <Toast show={warning} variant='warning' message={flash.warning} onClose={() => setWarning(false)} />
            {children}
        </SidebarLayout>
    );
}
