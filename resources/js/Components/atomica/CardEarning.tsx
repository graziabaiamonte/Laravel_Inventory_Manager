import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/Components/ui/card';
import VerticalBarChart from './VerticalBarChart';
import Combo from './Utils/Combo';
import { MapByYear, User } from '@/types';
import { useState } from 'react';
import { set } from 'date-fns';
import UseHasRoleOrPermissions from '@/Hooks/UseHasRoleOrPermissions';

export const CardEarning = ({
    title,
    description,
    commissions,
    earnings,
    symbol,
    users,
}: {
    title: string;
    description?: string;
    commissions: MapByYear;
    earnings: MapByYear;
    symbol?: string;
    users: Array<User>;
}) => {
    // UTILITIES

    const getYears = (): number[] => {
        return Object.keys(commissions).map(key => parseInt(key));
    };

    const getMonths = (year: number): number[] => {
        return Object.keys(commissions[year]).map(key => parseInt(key));
    };

    const getUsers = (year: number, month: number): number[] => {
        return Object.keys(commissions[year][month]).map(key => parseInt(key));
    };

    // UTILITIES FOR COMBO

    const getFirstMonth = (year: number) => {
        return getMonths(year)[0];
    };

    const getFirstUser = (year: number, month: number) => {
        return getUsers(year, month)[0];
    };

    // INITIAL VALUES

    const getInitialYear = () => {
        const years = getYears();
        return years[years.length - 1];
    };

    const getInitialMonth = () => {
        const year = getInitialYear();
        if (!year) return 0;
        const months = getMonths(year);
        return months[months.length - 1];
    };

    const getInitialUser = () => {
        const year = getInitialYear();
        const month = getInitialMonth();
        if (!year || !month) return 0;
        return getUsers(year, month)[0];
    };

    // USER NAMES

    const getUserNames = (user_ids: number[]) => {
        return users
            .filter(user => user_ids.includes(user.id))
            .map(user => {
                return { value: user.id, label: getFullName(user) };
            });
    };

    const getFullName = (user: User) => {
        return user.last_name ? user.name + ' ' + user.last_name : user.name;
    };

    // MONTH LABELS

    const getMonthLabel = (year: number, month: number) => {
        return new Date(year, month - 1).toLocaleString('default', { month: 'long' });
    };

    const getMonthLabels = (year: number, months: number[]) => {
        return months.map(month => {
            return { value: month, label: getMonthLabel(year, month) };
        });
    };

    const formatNumber = (number: number) => {
        return number.toFixed(2);
    };

    const hasProperties = (obj: object) => {
        return Object.keys(obj).length > 0;
    };

    const [year, setYear] = useState<number>(getInitialYear());
    const [month, setMonth] = useState<number>(getInitialMonth());
    const [user, setUser] = useState<number>(getInitialUser());

    if ((!commissions && !earnings) || (!hasProperties(commissions) && !hasProperties(earnings)))
        return (
            <Card className={`w-full`}>
                <CardHeader>
                    <CardTitle className='text-2xl'>{title}</CardTitle>
                    <CardDescription className='text-lg'>{description}</CardDescription>
                </CardHeader>
                <CardContent>
                    <CardDescription className='text-xl'>
                        Non ci sono nè provvigioni nè ragguagli al momento disponibili
                    </CardDescription>
                </CardContent>
            </Card>
        );

    return (
        <Card className={`w-full`}>
            <CardHeader>
                <CardTitle className='text-2xl'>{title}</CardTitle>
                <CardDescription className='text-lg'>{description}</CardDescription>
                <div className='flex flex-row gap-4'>
                    <Combo
                        items={getYears()}
                        label='Anno'
                        selected={year}
                        onChange={(year: number) => {
                            setYear(year);
                            setMonth(getFirstMonth(year));
                            setUser(getFirstUser(year, getFirstMonth(year)));
                        }}
                    />
                    {year && (
                        <Combo
                            items={getMonthLabels(year, getMonths(year))}
                            label='Mese'
                            displayValue='label'
                            selected={getMonthLabels(year, getMonths(year)).find(
                                selectedMonth => selectedMonth.value === month,
                            )}
                            onChange={(month: any) => {
                                setMonth(month.value);
                                setUser(getFirstUser(year, month.value));
                            }}
                        />
                    )}
                    {year && month && UseHasRoleOrPermissions({ permissions: ['admin', 'all'] }) && (
                        <Combo
                            items={getUserNames(getUsers(year, month))}
                            label='Agente'
                            displayValue='label'
                            selected={getUserNames(getUsers(year, month)).find(
                                selectedUser => selectedUser.value === user,
                            )}
                            onChange={(user: any) => {
                                setUser(user.value);
                            }}
                        />
                    )}
                </div>
            </CardHeader>
            <CardContent>
                {commissions && (
                    <CardDescription className='text-xl'>
                        Provvigioni: {symbol}
                        {formatNumber(commissions[year][month][user])}
                    </CardDescription>
                )}
                {!commissions && (
                    <CardDescription className='text-xl'>
                        Provvigioni: Non ci sono provvigioni al momento
                    </CardDescription>
                )}
                {earnings && (
                    <CardDescription className='text-xl'>
                        Ragguagli: {symbol}
                        {formatNumber(earnings[year][month][user])}
                    </CardDescription>
                )}
                {!earnings && (
                    <CardDescription className='text-xl'>Ragguagli: Non ci sono ragguagli al momento</CardDescription>
                )}
            </CardContent>
        </Card>
    );
};
