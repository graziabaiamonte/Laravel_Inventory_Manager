import { useEffect, FormEventHandler } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
// import TextInput from '@/Components/TextInput';
import Input from '@/Components/atomica/Forms/Input';
import { Head, Link, useForm } from '@inertiajs/react';

export default function Register() {
    const { data, setData, post, processing, errors, reset } = useForm({
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
    });

    useEffect(() => {
        return () => {
            reset('password', 'password_confirmation');
        };
    }, []);

    const submit: FormEventHandler = e => {
        e.preventDefault();

        post(route('register'));
    };

    return (
        <GuestLayout>
            <Head title='Register' />

            <form onSubmit={submit}>
                <div>
                    <Input
                        id='name'
                        label='Name'
                        name='name'
                        value={data.name}
                        className=''
                        isFocused={true}
                        onChange={e => setData('name', e.target.value)}
                        error={errors.name}
                        required
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        id='email'
                        type='email'
                        label='Email'
                        name='email'
                        value={data.email}
                        className=''
                        isFocused={true}
                        onChange={e => setData('email', e.target.value)}
                        error={errors.email}
                        required
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        label={'Password'}
                        id='password'
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        type='password'
                        error={errors.password}
                        required
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        label={'Conferma Password'}
                        id='password_confirmation'
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        type='password'
                        error={errors.password_confirmation}
                        required
                    />
                </div>

                <div className='flex items-center justify-end mt-4'>
                    <Link
                        href={route('login')}
                        className='underline text-sm text-gray-600 hover:text-gray-900 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary'>
                        {'Hai già un account?'}
                    </Link>

                    <PrimaryButton className='ms-4' disabled={processing}>
                        {'Registrati'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
