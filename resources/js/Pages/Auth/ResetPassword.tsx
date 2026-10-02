import { useEffect, FormEventHandler } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
// import TextInput from '@/Components/TextInput';
import Input from '@/Components/atomica/Forms/Input';
import { Head, useForm } from '@inertiajs/react';

export default function ResetPassword({ token, email }: { token: string; email: string }) {
    const { data, setData, post, processing, errors, reset } = useForm({
        token: token,
        email: email,
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

        post(route('password.store'));
    };

    return (
        <GuestLayout>
            <Head title='Reset Password' />

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
                        onChange={e => setData('email', e.target.value)}
                        error={errors.email}
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        label={'Password'}
                        id='password'
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        type='password'
                        isFocused={true}
                        autoComplete='new-password'
                        error={errors.password}
                    />
                </div>

                <div className='mt-4'>
                    <Input
                        label={'Conferma Password'}
                        id='password_confirmation'
                        value={data.password_confirmation}
                        onChange={e => setData('password_confirmation', e.target.value)}
                        type='password'
                        autoComplete='new-password'
                        error={errors.password_confirmation}
                    />
                </div>

                <div className='flex items-center justify-end mt-4'>
                    <PrimaryButton className='ms-4' disabled={processing}>
                        {'Reimposta Password'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
