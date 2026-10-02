import PrimaryButton from '@/Components/PrimaryButton';
import Input from '@/Components/atomica/Forms/Input';
import { Link, useForm, usePage } from '@inertiajs/react';
import { Transition } from '@headlessui/react';
import { FormEventHandler } from 'react';
import { PageProps } from '@/types';
import Toast from '@/Components/atomica/Alerts/Toast';
import Combo from '@/Components/atomica/Utils/Combo';

export default function UpdateProfileInformation({
    mustVerifyEmail,
    status,
    className = '',
    locations,
}: {
    mustVerifyEmail: boolean;
    status?: string;
    className?: string;
    locations: Array<any>;
}) {
    const user = usePage<PageProps>().props.auth.user;

    const { data, setData, patch, errors, processing, recentlySuccessful } = useForm({
        name: user.name || '',
        last_name: user.last_name || '',
        email: user.email || '',
        default_location_id: user.default_location_id || '',
    });

    const submit: FormEventHandler = e => {
        e.preventDefault();

        patch(route('profile.update'));
    };

    return (
        <section className={className}>
            <header>
                <h2 className='text-lg font-medium text-gray-900'>{'Profilo'}</h2>

                <p className='mt-1 text-sm text-gray-600'>
                    {'Aggiorna le informazioni del tuo profilo e il tuo indirizzo email.'}
                </p>
            </header>

            <form onSubmit={submit} className='mt-6 space-y-6'>
                <div>
                    <Input
                        label={'Nome'}
                        id='name'
                        value={data.name}
                        onChange={e => setData('name', e.target.value)}
                        required
                        isFocused
                        autoComplete='name'
                        error={errors.name}
                    />
                </div>

                <div>
                    <Input
                        label={'Cognome'}
                        id='last_name'
                        value={data.last_name}
                        onChange={e => setData('last_name', e.target.value)}
                        autoComplete='last_name'
                        error={errors.last_name}
                    />
                </div>

                <div>
                    <Input
                        label={'Email'}
                        id='email'
                        type='email'
                        value={data.email}
                        onChange={e => setData('email', e.target.value)}
                        required
                        autoComplete='username'
                        error={errors.email}
                    />
                </div>

                <div>
                    <Combo
                        items={locations}
                        error={errors.default_location_id}
                        label={'Location predefinita'}
                        displayValue={'name'}
                        onClear={() => {
                            setData(prev => ({
                                ...prev,
                                default_location_id: null,
                            }));
                        }}
                        selected={
                            data.default_location_id ? locations.find(s => s.id === data.default_location_id) : null
                        }
                        onChange={location => {
                            setData(prev => ({
                                ...prev,
                                default_location_id: location?.id || null,
                            }));
                        }}
                    />
                </div>

                {mustVerifyEmail && user.email_verified_at === null && (
                    <div>
                        <p className='text-sm mt-2 text-gray-800'>
                            {'Il tuo indirizzo email non è verificato'}
                            <Link
                                href={route('verification.send')}
                                method='post'
                                as='button'
                                className='underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary'>
                                {"Clicca qui per inviare nuovamente l'email di verifica."}
                            </Link>
                        </p>

                        {status === 'verification-link-sent' && (
                            <div className='mt-2 font-medium text-sm text-green-600'>
                                {'Una nuova email di verifica è stata inviata al tuo indirizzo email.'}
                            </div>
                        )}
                    </div>
                )}

                <div className='flex items-center gap-4'>
                    <PrimaryButton disabled={processing}>{'Salva'}</PrimaryButton>

                    <Toast
                        show={recentlySuccessful}
                        variant='success'
                        message={'Dati del profilo aggiornati'}
                        onClose={() => {}}
                    />
                </div>
            </form>
        </section>
    );
}
