import { ChevronLeftIcon, ChevronRightIcon } from '@heroicons/react/20/solid';
import { data } from 'autoprefixer';

export type PaginationLink = {
    active: boolean;
    label: string;
    url?: string;
};

export interface PaginationData {
    current_page: number;
    first_page_url?: string;
    last_page_url?: string;
    prev_page_url?: string;
    next_page_url?: string;
    from: number;
    last_page: number;
    links: Array<PaginationLink>;
    per_page: number;
    to: number;
    path: string;
    total: number;
    onPagination: (pagination: { page: string | number; per_page: string | number }) => void;
    perPage: Array<any>;
}

export default function Pagination({
    current_page,
    first_page_url,
    last_page_url,
    prev_page_url,
    from,
    last_page,
    links,
    per_page,
    to,
    path,
    next_page_url,
    total,
    onPagination,
    perPage = [10, 20, 30],
}: PaginationData) {
    function showLink(link: string) {
        return !link.includes(';');
    }

    function emitPagination({ page, per_page }: { page: string | number; per_page: string | number }) {
        if (Number(page) > 0 && Number(page) <= last_page) {
            onPagination({ page: page, per_page: per_page });
        }
    }

    return (
        <div className='w-full flex items-center justify-between border-t border-gray-200 bg-white px-4 py-3 sm:px-6'>
            <div className='sm:flex sm:flex-1 sm:items-center sm:justify-between'>
                <div className='hidden md:flex'>
                    <p className='text-sm text-gray-700'>
                        Showing <span className='font-medium'>1</span> to {to} <span className='font-medium'></span>
                        <span className='font-medium'>of {total}</span> total records
                    </p>
                </div>
                <div className='flex-1 mr-5'>
                    <div className=' justify-end hidden sm:flex items-center'>
                        <label
                            htmlFor='location'
                            className={`${links.length > 10 && 'md:hidden'}
                               block mr-3 h-full flex
                               justify-center items-center
                               text-sm font-medium
                               leading-6
                               text-gray-900`}>
                            Items per page
                        </label>
                        <select
                            id='location'
                            name='location'
                            className='block rounded-md border-0 py-1.5 pl-3 pr-10 text-gray-900 ring-1 ring-inset ring-gray-300 focus:ring-2 focus:ring-primary-600 sm:text-sm sm:leading-6'
                            value={per_page}
                            onChange={e => onPagination({ per_page: e.target.value, page: 1 })}>
                            {perPage.map(perPage => (
                                <option key={perPage}>{perPage}</option>
                            ))}
                        </select>
                    </div>
                </div>
                <div>
                    <nav className='isolate inline-flex -space-x-px rounded-md shadow-sm' aria-label='Pagination'>
                        <button
                            onClick={() => emitPagination({ page: --current_page, per_page: per_page })}
                            className={`${prev_page_url ? 'hover:bg-gray-50' : 'cursor-default'} relative inline-flex items-center rounded-l-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 focus:z-20 focus:outline-offset-0`}>
                            <span className='sr-only'>Previous</span>
                            <ChevronLeftIcon className='h-5 w-5' aria-hidden='true' />
                        </button>
                        {links.length &&
                            links.map(
                                (link, index) =>
                                    showLink(link.label) &&
                                    (link.active ? (
                                        <button
                                            key={index}
                                            aria-current='page'
                                            className='relative z-10 inline-flex items-center bg-indigo-600 px-4 py-2 text-sm font-semibold text-white focus:z-20 focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-indigo-600'>
                                            <p>{link.label}</p>
                                        </button>
                                    ) : (
                                        <button
                                            onClick={() => emitPagination({ per_page: per_page, page: link.label })}
                                            key={index}
                                            className='relative inline-flex items-center px-4 py-2 text-sm font-semibold text-gray-900 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0'>
                                            <p>{link.label}</p>
                                        </button>
                                    )),
                            )}
                        <button
                            onClick={() => emitPagination({ page: ++current_page, per_page: per_page })}
                            className={`${next_page_url ? 'hover:bg-gray-50' : 'cursor-default'} relative inline-flex items-center rounded-r-md px-2 py-2 text-gray-400 ring-1 ring-inset ring-gray-300 hover:bg-gray-50 focus:z-20 focus:outline-offset-0`}>
                            <span className='sr-only'>Next</span>
                            <ChevronRightIcon className='h-5 w-5' aria-hidden='true' />
                        </button>
                    </nav>
                </div>
            </div>
        </div>
    );
}
