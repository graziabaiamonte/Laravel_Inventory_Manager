/* eslint-disable react/prop-types */
import { useEffect, useRef, useState } from 'react';

declare global {
    interface Window {
        turnstile?: {
            render: (
                element: HTMLElement,
                options: {
                    sitekey: string;
                    theme?: 'light' | 'dark' | 'auto';
                    size?: 'normal' | 'compact' | 'flexible';
                    callback?: (token: string) => void;
                    'error-callback'?: () => void;
                    'expired-callback'?: () => void;
                    'timeout-callback'?: () => void;
                },
            ) => string;
            reset: (widgetId: string) => void;
            remove: (widgetId: string) => void;
        };
    }
}

export interface TurnstileProps {
    sitekey: string;
    theme?: 'light' | 'dark' | 'auto';
    size?: 'normal' | 'compact' | 'flexible';
    onSuccess?: (token: string) => void;
    onError?: () => void;
    onExpire?: () => void;
    onTimeout?: () => void;
    className?: string;
}

export const Turnstile: React.FC<TurnstileProps> = ({
    sitekey,
    theme = 'auto',
    size = 'normal',
    onSuccess,
    onError,
    onExpire,
    onTimeout,
    className = '',
}) => {
    const containerRef = useRef<HTMLDivElement>(null);
    const widgetIdRef = useRef<string | null>(null);
    const [isScriptLoaded, setIsScriptLoaded] = useState(false);

    // Store callbacks in refs to avoid re-rendering when they change
    const callbacksRef = useRef({ onSuccess, onError, onExpire, onTimeout });

    // Update callbacks ref when they change
    useEffect(() => {
        callbacksRef.current = { onSuccess, onError, onExpire, onTimeout };
    }, [onSuccess, onError, onExpire, onTimeout]);

    useEffect(() => {
        // Check if script is already loaded
        if (window.turnstile) {
            setIsScriptLoaded(true);
            return;
        }

        // Load the Turnstile script
        const script = document.createElement('script');
        script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
        script.async = true;
        script.defer = true;
        script.onload = () => setIsScriptLoaded(true);
        document.head.appendChild(script);

        return () => {
            // Clean up script if component unmounts before loading
            if (document.head.contains(script)) {
                document.head.removeChild(script);
            }
        };
    }, []);

    useEffect(() => {
        if (!isScriptLoaded || !window.turnstile || !containerRef.current || !sitekey) {
            return;
        }

        // Render the Turnstile widget
        try {
            widgetIdRef.current = window.turnstile.render(containerRef.current, {
                sitekey,
                theme,
                size,
                callback: (token: string) => callbacksRef.current.onSuccess?.(token),
                'error-callback': () => callbacksRef.current.onError?.(),
                'expired-callback': () => callbacksRef.current.onExpire?.(),
                'timeout-callback': () => callbacksRef.current.onTimeout?.(),
            });
        } catch (error) {
            console.error('Failed to render Turnstile widget:', error);
        }

        // Cleanup function
        return () => {
            if (widgetIdRef.current && window.turnstile) {
                try {
                    window.turnstile.remove(widgetIdRef.current);
                } catch (error) {
                    console.error('Failed to remove Turnstile widget:', error);
                }
            }
        };
    }, [isScriptLoaded, sitekey, theme, size]);

    if (!sitekey) {
        return null;
    }

    return <div ref={containerRef} className={className}></div>;
};

export default Turnstile;
