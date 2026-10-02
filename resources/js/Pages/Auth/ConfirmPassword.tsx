import { useEffect, FormEventHandler } from 'react';
import GuestLayout from '@/Layouts/GuestLayout';
import PrimaryButton from '@/Components/PrimaryButton';
// import TextInput from '@/Components/TextInput';
import Input from '@/Components/atomica/Forms/Input';
import { Head, useForm } from '@inertiajs/react';

export default function ConfirmPassword() {
    const { data, setData, post, processing, errors, reset } = useForm({
        password: '',
    });

    useEffect(() => {
        return () => {
            reset('password');
        };
    }, []);

    const submit: FormEventHandler = e => {
        e.preventDefault();

        post(route('password.confirm'));
    };

    return (
        <GuestLayout>
            <Head title='Confirm Password' />

            <div className='mb-4 text-sm text-gray-600'>
                This is a secure area of the application. Please confirm your password before continuing.
            </div>

            <form onSubmit={submit}>
                <div className='mt-4'>
                    <Input
                        label={'Password'}
                        id='password'
                        value={data.password}
                        onChange={e => setData('password', e.target.value)}
                        type='password'
                        isFocused={true}
                        autoComplete='password'
                        error={errors.password}
                    />
                </div>

                <div className='flex items-center justify-end mt-4'>
                    <PrimaryButton className='ms-4' disabled={processing}>
                        {'Conferma'}
                    </PrimaryButton>
                </div>
            </form>
        </GuestLayout>
    );
}
