import { useEffect, useRef } from 'react';

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

export interface UseTurnstileOptions {
    sitekey: string;
    theme?: 'light' | 'dark' | 'auto';
    size?: 'normal' | 'compact' | 'flexible';
    onSuccess?: (token: string) => void;
    onError?: () => void;
    onExpire?: () => void;
    onTimeout?: () => void;
}

/**
 * React hook for Cloudflare Turnstile
 *
 * @example
 * ```tsx
 * const { turnstileRef } = useTurnstile({
 *   sitekey: 'your-sitekey',
 *   onSuccess: (token) => setData('cf-turnstile-response', token),
 * });
 *
 * return <div ref={turnstileRef}></div>;
 * ```
 */
export const useTurnstile = (options: UseTurnstileOptions) => {
    const { sitekey, theme = 'auto', size = 'normal', onSuccess, onError, onExpire, onTimeout } = options;

    const turnstileRef = useRef<HTMLDivElement>(null);
    const widgetIdRef = useRef<string | null>(null);
    const callbacksRef = useRef({ onSuccess, onError, onExpire, onTimeout });

    // Update callbacks ref when they change, without triggering re-render
    useEffect(() => {
        callbacksRef.current = { onSuccess, onError, onExpire, onTimeout };
    }, [onSuccess, onError, onExpire, onTimeout]);

    useEffect(() => {
        if (!sitekey) return;

        // Load the Turnstile script if not already loaded
        if (!window.turnstile) {
            const script = document.createElement('script');
            script.src = 'https://challenges.cloudflare.com/turnstile/v0/api.js';
            script.async = true;
            script.defer = true;
            script.onload = () => {
                renderWidget();
            };
            document.head.appendChild(script);
        } else {
            renderWidget();
        }

        function renderWidget() {
            if (window.turnstile && turnstileRef.current) {
                widgetIdRef.current = window.turnstile.render(turnstileRef.current, {
                    sitekey,
                    theme,
                    size,
                    callback: token => callbacksRef.current.onSuccess?.(token),
                    'error-callback': () => callbacksRef.current.onError?.(),
                    'expired-callback': () => callbacksRef.current.onExpire?.(),
                    'timeout-callback': () => callbacksRef.current.onTimeout?.(),
                });
            }
        }

        return () => {
            if (widgetIdRef.current && window.turnstile) {
                window.turnstile.remove(widgetIdRef.current);
            }
        };
    }, [sitekey, theme, size]);

    const reset = () => {
        if (widgetIdRef.current && window.turnstile) {
            window.turnstile.reset(widgetIdRef.current);
        }
    };

    return {
        turnstileRef,
        reset,
    };
};
