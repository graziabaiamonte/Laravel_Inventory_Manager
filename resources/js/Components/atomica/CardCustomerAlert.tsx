import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/Components/ui/card';
import { Button } from '@/Components/ui/button';
import { Link } from '@inertiajs/react';

export const CardCustomerAlert = ({ count }: { count: number }) => {
    return (
        <Card className='w-full'>
            <CardHeader>
                <CardTitle className='text-2xl'>Clienti senza ordini</CardTitle>
                <CardDescription className='text-lg'>
                    Clienti che non hanno effettuato ordini nell&apos;ultimo mese
                </CardDescription>
            </CardHeader>
            <CardContent>
                <div className='flex flex-col gap-4'>
                    <p className='text-lg'>
                        {count > 0 ? (
                            <>
                                Ci sono <span className='font-bold text-2xl'>{count}</span> clienti senza ordini
                                nell&apos;ultimo mese
                            </>
                        ) : (
                            "Nessun cliente senza ordini nell'ultimo mese"
                        )}
                    </p>
                    {count > 0 && (
                        <div>
                            <Link href='/customer?filter%5Bno_recent_orders%5D=1month&page=1'>
                                <Button>Visualizza clienti</Button>
                            </Link>
                        </div>
                    )}
                </div>
            </CardContent>
        </Card>
    );
};
