import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
import { Head, Link, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function VerifyEmail({ status }: { status?: string }) {
    const { post, processing } = useForm({});

    const submit: FormEventHandler = e => {
        e.preventDefault();

        post(route('verification.send'));
    };

    return (
        <GuestLayout>
            <Head title='Email Verification' />

            <div className='mb-4 text-sm text-gray-600'>
                {
                    "Grazie per esserti registrato. Prima di iniziare, verifica la tua email cliccando sul link che ti abbiamo appena inviato. Se non hai ricevuto l'email, puoi richiedere un nuovo invio."
                }
            </div>

            {status === 'verification-link-sent' && (
                <div className='mb-4 font-medium text-sm text-green-600'>
                    {
                        "Un nuovo link per la verifica è stato inviato all'indirizzo email usato in fase di registrazione."
                    }
                </div>
            )}

            <form onSubmit={submit}>
                <div className='mt-4 flex items-center justify-between'>
                    <PrimaryButton disabled={processing}>{'Re-invia link di verifica'}</PrimaryButton>

                    <Link
                        href={route('logout')}
                        method='post'
                        as='button'
                        className='underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary'>
                        {'Logout'}
                    </Link>
                </div>
            </form>
        </GuestLayout>
    );
}
