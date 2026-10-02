import { useEffect, useRef } from 'react';

const DRAG_THRESHOLD_PX = 5;
const STYLE_ID = 'use-drag-scroll-styles';

function ensureStyles() {
    if (typeof document === 'undefined' || document.getElementById(STYLE_ID)) return;
    const style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = `
        .drag-scroll-wrapper { cursor: grab; }
        .drag-scroll-wrapper thead,
        .drag-scroll-wrapper thead * { cursor: grab !important; }
        .drag-scroll-wrapper.is-drag-scrolling,
        .drag-scroll-wrapper.is-drag-scrolling * { cursor: grabbing !important; user-select: none !important; }
    `;
    document.head.appendChild(style);
}

function isPointOnTextNode(el: HTMLElement, x: number, y: number): boolean {
    const range = document.createRange();
    for (const child of Array.from(el.childNodes)) {
        if (child.nodeType !== Node.TEXT_NODE) continue;
        if (!child.nodeValue || !child.nodeValue.trim()) continue;
        range.selectNodeContents(child);
        for (const rect of Array.from(range.getClientRects())) {
            if (x >= rect.left && x <= rect.right && y >= rect.top && y <= rect.bottom) {
                return true;
            }
        }
    }
    return false;
}

export function useDragScroll<T extends HTMLElement>() {
    const ref = useRef<T | null>(null);

    useEffect(() => {
        const el = ref.current;
        if (!el) return;
        ensureStyles();

        let isDown = false;
        let startX = 0;
        let startScrollLeft = 0;
        let lastDx = 0;
        let moved = false;
        let rafId = 0;

        const applyScroll = () => {
            rafId = 0;
            el.scrollLeft = startScrollLeft - lastDx;
        };

        const onMouseDown = (e: MouseEvent) => {
            const target = e.target as HTMLElement | null;
            if (!target) return;

            // Skip text-input controls — they handle their own selection.
            // Buttons/links remain draggable; static clicks fire through click-cancel below.
            if (target.closest('input, textarea, select, [role="combobox"], [contenteditable="true"]')) {
                return;
            }

            // Skip clicks on td content (preserve cell padding as drag surface).
            const parentTd = target.closest('td');
            if (parentTd) {
                if (target !== parentTd) return;
                if (isPointOnTextNode(parentTd, e.clientX, e.clientY)) return;
            }

            isDown = true;
            moved = false;
            startX = e.pageX;
            startScrollLeft = el.scrollLeft;
            lastDx = 0;
            el.classList.add('is-drag-scrolling');

            window.addEventListener('mousemove', onWindowMouseMove);
            window.addEventListener('mouseup', stop);
        };

        const onWindowMouseMove = (e: MouseEvent) => {
            if (!isDown) return;
            const dx = e.pageX - startX;
            if (Math.abs(dx) > DRAG_THRESHOLD_PX) moved = true;
            lastDx = dx;
            if (!rafId) rafId = requestAnimationFrame(applyScroll);
        };

        const onHoverMove = (e: MouseEvent) => {
            if (isDown) return;
            const target = e.target as HTMLElement | null;
            const interactive = target?.closest(
                'input, textarea, select, button, a, [role="combobox"], [role="button"], [contenteditable="true"]',
            );
            const parentTd = target?.closest('td');
            const onText =
                !interactive &&
                !!parentTd &&
                (target !== parentTd || isPointOnTextNode(parentTd, e.clientX, e.clientY));
            el.style.cursor = onText ? 'text' : 'grab';
        };

        const stop = () => {
            isDown = false;
            if (rafId) {
                cancelAnimationFrame(rafId);
                rafId = 0;
            }
            el.classList.remove('is-drag-scrolling');
            window.removeEventListener('mousemove', onWindowMouseMove);
            window.removeEventListener('mouseup', stop);
        };

        const onClickCapture = (e: MouseEvent) => {
            if (moved) {
                e.preventDefault();
                e.stopPropagation();
                moved = false;
            }
        };

        el.addEventListener('mousedown', onMouseDown);
        el.addEventListener('mousemove', onHoverMove);
        el.addEventListener('click', onClickCapture, true);

        return () => {
            stop();
            el.removeEventListener('mousedown', onMouseDown);
            el.removeEventListener('mousemove', onHoverMove);
            el.removeEventListener('click', onClickCapture, true);
        };
    }, []);

    return ref;
}
