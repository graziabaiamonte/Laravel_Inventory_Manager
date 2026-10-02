import { useEffect, FormEventHandler } from 'react';
import Checkbox from '@/Components/Checkbox';
import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
// import TextInput from '@/Components/TextInput';
import { Head, Link, useForm } from '@inertiajs/react';
import Input from '@/Components/atomica/Forms/Input';
import { useTurnstile } from '@/Components/Turnstile';

export default function Login({
    status,
    canResetPassword,
    turnstile_sitekey,
}: {
    status?: string;
    canResetPassword: boolean;
    turnstile_sitekey: string;
}) {
    const { data, setData, post, processing, errors, reset } = useForm({
        email: '',
        password: '',
        remember: false,
        'cf-turnstile-response': '',
    });

    const { turnstileRef } = useTurnstile({
        sitekey: turnstile_sitekey,
        onSuccess: (token: string) => setData('cf-turnstile-response', token),
    });

    useEffect(() => {
        return () => {
            reset('password');
        };
    }, []);

    const submit: FormEventHandler = e => {
        e.preventDefault();
        post(route('login'));
    };

    return (
        <GuestLayout>
            <Head title='Log in' />

            {status && <div className='mb-4 font-medium text-sm text-green-600'>{status}</div>}

            <form onSubmit={submit}>
                <div>
                    <Input
                        id='email'
                        type='email'
                        label='Email'
                        name='email'
                        value={data.email}
                        className=''
                        autoComplete='username'
                        isFocused={true}
                        onChange={e => setData('email', e.target.value)}
                        error={errors.email}
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        label={'Password'}
                        id='password'
                        type='password'
                        name='password'
                        value={data.password}
                        className=''
                        autoComplete='current-password'
                        onChange={e => setData('password', e.target.value)}
                        error={errors.password}
                    />
                </div>

                <div className='mt-4'>
                    <div ref={turnstileRef}></div>
                    {errors['cf-turnstile-response'] && (
                        <div className='mt-2 text-sm text-red-600'>{errors['cf-turnstile-response']}</div>
                    )}
                </div>

                <div className='block mt-4'>
                    <label className='flex items-center'>
                        <Checkbox
                            checked={data.remember}
                            onChange={e => setData('remember', e.target.checked as false)}
                        />
                        <span className='ml-2 text-sm text-gray-600'>{'Rimani loggato'}</span>
                    </label>
                </div>

                <div className='flex items-center justify-end mt-4'>
                    {canResetPassword && (
                        <Link
                            href={route('password.request')}
                            className='underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary'>
                            {'Password dimenticata?'}
                        </Link>
                    )}

                    <PrimaryButton className='ml-4' disabled={processing}>
                        {'Accedi'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
