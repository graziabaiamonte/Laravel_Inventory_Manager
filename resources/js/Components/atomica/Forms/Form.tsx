import { PropsWithChildren } from 'react';
import PrimaryButton from '@/Components/PrimaryButton';

export default function Form({
    children,
    submit,
    action,
    method,
    formName,
    className = '',
    btnText = 'Invia',
    noBtn = false,
}: PropsWithChildren<{
    submit?: any;
    action?: string;
    method?: string;
    formName?: string;
    className?: string;
    btnText?: string;
    noBtn?: boolean;
}>) {
    return (
        <form
            name={formName}
            method={method}
            action={action || '#'}
            onSubmit={e => {
                if (typeof submit !== 'undefined') {
                    e.preventDefault();
                    submit();
                }
            }}
            className={className}>
            {children}
            {!noBtn && (
                <PrimaryButton type='submit' className='w-full py-5 flex justify-center mt-5'>
                    {btnText}
                </PrimaryButton>
            )}
        </form>
    );
}
