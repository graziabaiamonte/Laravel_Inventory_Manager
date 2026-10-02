import { CheckCircleIcon, ExclamationCircleIcon, ExclamationTriangleIcon, XMarkIcon } from '@heroicons/react/20/solid';
import { Transition } from '@headlessui/react';
import { useEffect, useRef } from 'react';

type ToastVariant = 'success' | 'error' | 'warning';

interface ToastProps {
    show: boolean;
    message?: string;
    variant?: ToastVariant;
    /** Auto-dismiss the toast. Defaults to true for success, false for error/warning. */
    autoDismiss?: boolean;
    /** Auto-dismiss delay in ms. Defaults to 5000. */
    dismissDelay?: number;
    onClose: () => void;
}

const variantConfig: Record<
    ToastVariant,
    {
        bg: string;
        text: string;
        iconColor: string;
        closeColor: string;
        Icon: typeof CheckCircleIcon;
        defaultMessage: string;
        defaultAutoDismiss: boolean;
    }
> = {
    success: {
        bg: 'bg-green-50 border border-green-200',
        text: 'text-green-800',
        iconColor: 'text-green-400',
        closeColor: 'text-green-500 hover:text-green-700',
        Icon: CheckCircleIcon,
        defaultMessage: 'Successo',
        defaultAutoDismiss: true,
    },
    error: {
        bg: 'bg-red-50 border border-red-200',
        text: 'text-red-800',
        iconColor: 'text-red-400',
        closeColor: 'text-red-500 hover:text-red-700',
        Icon: ExclamationCircleIcon,
        defaultMessage: 'Si è verificato un errore',
        defaultAutoDismiss: false,
    },
    warning: {
        bg: 'bg-amber-50 border border-amber-200',
        text: 'text-amber-800',
        iconColor: 'text-amber-500',
        closeColor: 'text-amber-500 hover:text-amber-700',
        Icon: ExclamationTriangleIcon,
        defaultMessage: 'Warning',
        defaultAutoDismiss: false,
    },
};

export default function Toast({
    show,
    message,
    variant = 'success',
    autoDismiss,
    dismissDelay = 5000,
    onClose,
}: ToastProps) {
    const config = variantConfig[variant];
    const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

    const shouldAutoDismiss = autoDismiss ?? config.defaultAutoDismiss;

    useEffect(() => {
        if (timerRef.current) {
            clearTimeout(timerRef.current);
            timerRef.current = null;
        }

        if (show && shouldAutoDismiss) {
            timerRef.current = setTimeout(onClose, dismissDelay);
        }

        return () => {
            if (timerRef.current) {
                clearTimeout(timerRef.current);
            }
        };
    }, [show, shouldAutoDismiss, dismissDelay, onClose]);

    const displayMessage = message || config.defaultMessage;
    const isHtml = message?.includes('<') ?? false;

    return (
        <Transition
            enter='transition-all duration-150'
            enterFrom='opacity-0 translate-x-4'
            enterTo='opacity-100 translate-x-0'
            leave='transition-all duration-200'
            leaveFrom='opacity-100 translate-x-0'
            leaveTo='opacity-0 translate-x-4'
            show={show}>
            <div
                className={`max-w-lg rounded-md shadow-lg ${config.bg} p-4 fixed top-20 right-4 sm:right-6 lg:right-8 z-[100]`}>
                <div className='flex'>
                    <div className='flex-shrink-0'>
                        <config.Icon className={`h-5 w-5 ${config.iconColor}`} aria-hidden='true' />
                    </div>
                    <div className='ml-3 flex-1'>
                        {isHtml ? (
                            <div
                                className={`text-sm font-medium ${config.text} [&_a]:underline [&_a]:font-semibold [&_ul]:list-disc [&_ul]:ml-4 [&_li]:py-0.5`}
                                dangerouslySetInnerHTML={{ __html: displayMessage }}
                            />
                        ) : (
                            <p className={`text-sm font-medium ${config.text}`}>{displayMessage}</p>
                        )}
                    </div>
                    <div className='ml-3 flex-shrink-0'>
                        <button
                            type='button'
                            className={`inline-flex rounded-md ${config.closeColor} focus:outline-none`}
                            onClick={onClose}>
                            <XMarkIcon className='h-5 w-5' aria-hidden='true' />
                        </button>
                    </div>
                </div>
            </div>
        </Transition>
    );
}
