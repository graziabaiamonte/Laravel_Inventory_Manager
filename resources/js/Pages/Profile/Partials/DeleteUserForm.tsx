import { useRef, useState, FormEventHandler } from 'react';
import DangerButton from '@/Components/DangerButton';
import Modal from '@/Components/Modal';
import SecondaryButton from '@/Components/SecondaryButton';
// import TextInput from '@/Components/TextInput';
import Input from '@/Components/atomica/Forms/Input';
import { useForm } from '@inertiajs/react';

export default function DeleteUserForm({ className = '' }: { className?: string }) {
    const [confirmingUserDeletion, setConfirmingUserDeletion] = useState(false);
    const passwordInput = useRef<HTMLInputElement>(null);

    const {
        data,
        setData,
        delete: destroy,
        processing,
        reset,
        errors,
    } = useForm({
        password: '',
    });

    const confirmUserDeletion = () => {
        setConfirmingUserDeletion(true);
    };

    const deleteUser: FormEventHandler = e => {
        e.preventDefault();

        destroy(route('profile.destroy'), {
            preserveScroll: true,
            onSuccess: () => closeModal(),
            onError: () => passwordInput.current?.focus(),
            onFinish: () => reset(),
        });
    };

    const closeModal = () => {
        setConfirmingUserDeletion(false);

        reset();
    };

    return (
        <section className={`space-y-6 ${className}`}>
            <header>
                <h2 className='text-lg font-medium text-gray-900'>Delete Account</h2>

                <p className='mt-1 text-sm text-gray-600'>
                    Once your account is deleted, all of its resources and data will be permanently deleted. Before
                    deleting your account, please download any data or information that you wish to retain.
                </p>
            </header>

            <DangerButton onClick={confirmUserDeletion}>Delete Account</DangerButton>

            <Modal show={confirmingUserDeletion} onClose={closeModal}>
                <form onSubmit={deleteUser} className='p-6'>
                    <h2 className='text-lg font-medium text-gray-900'>{'Confermi la rimozione del tuo account?'}</h2>

                    <p className='mt-1 text-sm text-gray-600'>
                        {
                            "Una volta eliminato, il tuo account e tutti i tuoi dati saranno permanentemente eliminati. Per favore inserisci la tua password per confermare l'eliminazione definitiva del tuo account."
                        }
                    </p>

                    <div className='mt-6'>
                        <Input
                            label={'Password'}
                            id='password'
                            name='password'
                            ref={passwordInput}
                            value={data.password}
                            onChange={e => setData('password', e.target.value)}
                            type='password'
                            error={errors.password}
                        />
                    </div>

                    <div className='mt-6 flex justify-end'>
                        <SecondaryButton onClick={closeModal}>{'Annulla'}</SecondaryButton>

                        <DangerButton className='ms-3' disabled={processing}>
                            {'Elimina account'}
                        </DangerButton>
                    </div>
                </form>
            </Modal>
        </section>
    );
}
