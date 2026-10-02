import GuestLayout from '@/Layouts/GuestLayout';
// import InputError from '@/Components/InputError';
import PrimaryButton from '@/Components/PrimaryButton';
// import TextInput from '@/Components/TextInput';
import Input from '@/Components/atomica/Forms/Input';
import { Head, useForm } from '@inertiajs/react';
import { FormEventHandler } from 'react';

export default function ForgotPassword({ status }: { status?: string }) {
    const { data, setData, post, processing, errors } = useForm({
        email: '',
    });

    const submit: FormEventHandler = e => {
        e.preventDefault();

        post(route('password.email'));
    };

    return (
        <GuestLayout>
            <Head title='Forgot Password' />

            <div className='mb-4 text-sm text-gray-600'>
                {'Insersci la tua email per ricevere un link per il reset della password.'}
            </div>

            {status && <div className='mb-4 font-medium text-sm text-green-600'>{status}</div>}

            <form onSubmit={submit}>
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
                />

                <div className='flex items-center justify-end mt-4'>
                    <PrimaryButton className='ms-4' disabled={processing}>
                        {'Invia link'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
