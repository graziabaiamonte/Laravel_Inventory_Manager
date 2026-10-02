import Authenticated from '@/Layouts/AuthenticatedLayout';
import Input from '@/Components/atomica/Forms/Input';
import Form from '@/Components/atomica/Forms/Form';
import { useForm, router } from '@inertiajs/react';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import Combo from '@/Components/atomica/Utils/Combo';
import Autocomplete from '@/Components/atomica/Utils/Autocomplete';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import FormSection from '@/Components/atomica/Forms/FormSection';
import Dropzone from '@/Components/atomica/Forms/Dropzone';
import { Trash, FileText, Search } from 'lucide-react';
import { useState, useEffect, useCallback } from 'react';
import Modal from '@/Components/atomica/Utils/Modal';
import DiscogsSearchResult from './Components/DiscogsSearchResult';
import axios from 'axios';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';
import StockList from './Components/StockList';
import { Stock } from '@/types';
import { useCleanBarcodeInput } from '@/Hooks/useCleanBarcodeInput';
import DiscogsZeroPriceModal from '@/Components/records/DiscogsZeroPriceModal';
import { isZeroPrice } from '@/lib/utils';

interface FormData {
    barcode: string;
    cat_number: string;
    release_id: string;
    type: string;
    title: string;
    retail_price: number | string;
    wholesale_price: number | string;
    purchase_price: number | string;
    disk_status: number;
    cover_status: number;
    for_sale_on_discogs: number;
    description: string;
    comments: string;
    location_text: string;
    format_id: number;
    artist_id: number | null;
    artist_name?: string;
    label_id: number | null;
    label_name?: string;
    format: any;
    artist: any;
    label: any;
    stock?: any[];
    // available_quantity?: number;
    media_upload: Array<File>;
    discogs_image_url?: string;
    [key: string]: any;
}

// DEBUG
// function useFormDebug(formData: FormData) {
//     useEffect(() => {
//         console.log('Form data changed:', {
//             title: formData.title,
//             label_id: formData.label_id,
//             artist_id: formData.artist_id,
//             cat_number: formData.cat_number,
//             barcode: formData.barcode,
//             release_id: formData.release_id,
//         });
//     }, [
//         formData.title,
//         formData.label_id,
//         formData.artist_id,
//         formData.cat_number,
//         formData.barcode,
//         formData.release_id,
//     ]);
// }

export default function Create({
    types,
    formats,
    diskstatus,
    coverstatus,
    forsalediscogsstatus,
    areas,
}: {
    types: Array<any>;
    formats: Array<any>;
    diskstatus: Array<any>;
    coverstatus: Array<any>;
    forsalediscogsstatus: Array<any>;
    areas: Array<any>;
}) {
    const form = useForm<FormData>({
        barcode: '',
        cat_number: '',
        release_id: '',
        type: '',
        title: '',
        retail_price: '',
        wholesale_price: '',
        purchase_price: '',
        disk_status: null as any,
        cover_status: null as any,
        description: '',
        comments: '',
        location_text: '',
        format_id: 0,
        artist_id: null,
        artist_name: '',
        label_id: null,
        label_name: '',
        for_sale_on_discogs: 0,
        media_upload: [],
        format: null,
        artist: null,
        label: null,
        discogs_image_url: '',
        stock: [],
    });
    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('record');

    const [showZeroPriceModal, setShowZeroPriceModal] = useState(false);

    // Putting a record on sale on Discogs with a retail price of 0 has to be confirmed first
    const handleSubmit = () => {
        if (Number(form.data.for_sale_on_discogs) === 1 && isZeroPrice(form.data.retail_price)) {
            setShowZeroPriceModal(true);
            return;
        }

        submit();
    };

    // Custom handler for barcode input with cleanup
    const handleBarcodeChange = useCleanBarcodeInput(form.setData);

    function onFileDelete(file: any) {
        form.setData(prev => ({
            ...prev,
            media_upload: form.data.media_upload.filter((item: File) => item.name !== file.name),
        }));
    }

    function onFileChange(file: File) {
        form.setData(prev => ({
            ...prev,
            media_upload: [file],
        }));
    }

    const [discogsResults, setDiscogsResults] = useState<any[]>([]);
    const [showDiscogsModal, setShowDiscogsModal] = useState(false);
    const [discogsNotices, setDiscogsNotices] = useState<{
        release_id?: string;
        artist?: string;
        label?: string;
    }>({});

    const searchOnDiscogs = (releaseId?: string | number) => {
        const release_id = releaseId || form.data.release_id;
        const barcode = form.data.barcode;
        const cat_number = form.data.cat_number;

        if (release_id) {
            // Call discogs.getRelease route
            router.post(
                route('discogs.getRelease'),
                {
                    release_id: release_id,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: async response => {
                        //console.log('Discogs getRelease result:', response);

                        const discogsData = (response.props.flash as any).discogsData;

                        if (!discogsData) {
                            setDiscogsNotices({
                                release_id: String(release_id),
                            });
                            return;
                        }

                        // Fetch artists data
                        const artistsResponse = await axios.get(
                            route('artist.index', {
                                filter: { ['name']: discogsData.artist },
                            }),
                            {
                                headers: {
                                    Accept: 'application/json',
                                },
                            },
                        );

                        //console.log(artistsResponse);

                        const labelsResponse = await axios.get(
                            route('label.index', {
                                filter: { ['name']: discogsData.label },
                            }),
                            {
                                headers: {
                                    Accept: 'application/json',
                                },
                            },
                        );

                        const artists = artistsResponse.data;
                        const labels = labelsResponse.data;

                        form.setData(prev => {
                            const matchingArtist = discogsData.artist
                                ? artists.find((a: any) => a.name.toLowerCase() === discogsData.artist.toLowerCase())
                                : null;

                            //const matchingArtist = null;

                            const matchingLabel = discogsData.label
                                ? labels.find((l: any) => l.name.toLowerCase() === discogsData.label.toLowerCase())
                                : null;

                            //const matchingLabel = null;

                            const notices: { artist?: string; label?: string } = {};
                            if (discogsData.artist && !matchingArtist) {
                                notices.artist = discogsData.artist;
                            }
                            if (discogsData.label && !matchingLabel) {
                                notices.label = discogsData.label;
                            }
                            setDiscogsNotices(notices);

                            const newData = {
                                ...prev,
                                title: discogsData.title || prev.title,
                                cat_number: discogsData.catno || prev.cat_number,
                                barcode: discogsData.barcode || prev.barcode,
                                release_id: discogsData.releaseId || prev.release_id,
                                label_id: matchingLabel ? matchingLabel.id : discogsData.label ? 0 : prev.label_id,
                                label_name: matchingLabel ? '' : discogsData.label || '',
                                artist_id: matchingArtist ? matchingArtist.id : discogsData.artist ? 0 : prev.artist_id,
                                artist_name: matchingArtist ? '' : discogsData.artist || '',
                                discogs_image_url: discogsData.imgURL || '',
                                label:
                                    matchingLabel ||
                                    (discogsData.label ? { id: 0, name: discogsData.label } : prev.label),
                                artist:
                                    matchingArtist ||
                                    (discogsData.artist ? { id: 0, name: discogsData.artist } : prev.artist),
                            };

                            //console.log('Setting form data to:', newData);
                            return newData;
                        });
                    },
                    onError: errors => {
                        console.error('Discogs getRelease error:', errors);
                    },
                },
            );
        } else if (barcode || cat_number) {
            // Call discogs.search route
            router.post(
                route('discogs.search'),
                {
                    barcode: barcode || '',
                    cat_number: cat_number || '',
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onSuccess: response => {
                        //console.log('Discogs search result:', response);

                        const discogsData = (response.props.flash as any).discogsData;

                        //console.log('Discogs search data:', discogsData);

                        if (discogsData) {
                            setDiscogsResults(discogsData);
                            setShowDiscogsModal(true);
                        }
                    },
                    onError: errors => {
                        console.error('Discogs search error:', errors);
                    },
                },
            );
        } else {
            console.warn('No release_id, barcode, or cat_number available for Discogs search');
        }
    };

    function getPrefillParams() {
        const params = new URLSearchParams(window.location.search);
        const prefill = {
            cat_number: params.get('cat_number') || '',
            barcode: params.get('barcode') || '',
            artist: params.get('artist') || '',
            title: params.get('title') || '',
            quantity: params.get('quantity') || '',
            format: params.get('format') || '',
            label: params.get('label') || '',
            price: params.get('price') || '',
        };

        // Se tutti i valori sono vuoti, ritorna null
        const allEmpty = Object.values(prefill).every(val => !val);
        return allEmpty ? null : prefill;
    }

    useEffect(() => {
        const prefill = getPrefillParams();

        if (!prefill) return;

        form.setData(prev => ({
            ...prev,
            cat_number: prefill.cat_number,
            barcode: prefill.barcode,
            title: prefill.title,
        }));

        if (prefill.price) {
            const numericValue = parseFloat(prefill.price);

            form.setData(prev => ({
                ...prev,
                wholesale_price: numericValue,
            }));

            // Focus and blur the wholesale price input so CurrencyInput re-formats the prefilled value
            setTimeout(() => {
                const input = document.getElementById('wholesale_price');
                if (input instanceof HTMLInputElement) {
                    input.focus();
                    setTimeout(() => input.blur(), 50);
                }
            }, 0);
        }

        if (prefill.artist) {
            axios.get(route('artist.index', { filter: { name: prefill.artist } })).then(response => {
                const artists = response.data;
                const matchingArtist = artists.find(
                    (a: any) => a.name.toLowerCase().trim() == prefill.artist.toLowerCase().trim(),
                );

                form.setData(prev => ({
                    ...prev,
                    artist: matchingArtist || { id: 0, name: prefill.artist },
                    artist_id: matchingArtist ? matchingArtist.id : 0,
                }));
            });
        }

        if (prefill.label) {
            axios.get(route('label.index', { filter: { name: prefill.label } })).then(response => {
                const labels = response.data;
                const matchingLabel = labels.find(
                    (a: any) => a.name.toLowerCase().trim() == prefill.label.toLowerCase().trim(),
                );

                form.setData(prev => ({
                    ...prev,
                    label: matchingLabel || { id: 0, name: prefill.label },
                    label_id: matchingLabel ? matchingLabel.id : 0,
                }));
            });
        }

        if (prefill.format) {
            const matchingFormat = formats.find(
                (f: any) => f.name.toLowerCase().trim() === prefill.format.toLowerCase().trim(),
            );

            if (matchingFormat) {
                form.setData(prev => ({
                    ...prev,
                    format_id: parseInt(matchingFormat.id),
                }));
            }
        }
    }, []);

    // DEBUG
    // useFormDebug(form.data);

    // eslint-disable-next-line @typescript-eslint/no-unused-vars
    const { isDirty, setIsDirty } = useUnsavedChanges({
        formData: form.data,
        initialData: {
            barcode: '',
            cat_number: '',
            release_id: '',
            type: '',
            title: '',
            retail_price: '',
            wholesale_price: '',
            purchase_price: '',
            disk_status: 0,
            cover_status: 0,
            description: '',
            comments: '',
            location_text: '',
            format_id: 0,
            artist_id: 0,
            label_id: 0,
            for_sale_on_discogs: 0,
            discogs_image_url: '',
            stock: [], // Initial stock is empty
        },
        editableFields: [
            'barcode',
            'cat_number',
            'release_id',
            'format_id',
            'label_id',
            'artist_id',
            'title',
            'retail_price',
            'wholesale_price',
            'purchase_price',
            'type',
            'disk_status',
            'cover_status',
            'description',
            'comments',
            'location_text',
            'for_sale_on_discogs',
            'discogs_image_url',
        ],
        recordsConfig: {
            field: 'stock',
            editableFields: ['area_id', 'quantity', 'description'], // Track stock changes too
            numericFields: ['area_id', 'quantity'],
        },
    });

    // Reset unsaved changes on successful form submission
    useEffect(() => {
        if (form.wasSuccessful) {
            setIsDirty(false);
            setDiscogsNotices({});
        }
    }, [form.wasSuccessful, setIsDirty]);

    const handleStocksUpdate = useCallback(
        (updatedStocks: Stock[]) => {
            form.setData('stock', updatedStocks);
        },
        [form.setData],
    );

    return (
        <Authenticated title='Nuovo Record'>
            <Modal type='primary' show={showDiscogsModal} onClose={() => setShowDiscogsModal(false)} maxWidth='4xl'>
                <div className='flex items-center justify-between mb-4'>
                    <h3 className='text-lg font-medium text-gray-900'>Risultati Discogs ({discogsResults.length})</h3>
                </div>

                <div className='max-h-96 overflow-y-auto'>
                    <div className='grid sm:grid-cols-2 md:grid-cols-3 gap-2'>
                        {discogsResults.map((result, index) => (
                            <DiscogsSearchResult
                                key={result.id || index}
                                result={result}
                                onSelect={result => {
                                    //console.log('Selected Discogs result:', result);
                                    searchOnDiscogs(result.id);
                                    setShowDiscogsModal(false);
                                }}
                            />
                        ))}
                    </div>
                </div>
            </Modal>

            <DiscogsZeroPriceModal
                show={showZeroPriceModal}
                onClose={() => setShowZeroPriceModal(false)}
                onConfirm={() => {
                    setShowZeroPriceModal(false);
                    submit();
                }}
            />

            <div className='flex justify-end gap-1'>
                <SecondaryButton className='-translate-y-full mt-1' onClick={() => searchOnDiscogs()}>
                    <Search className='h-4 w-4' />
                    <span className='hidden sm:block ms-2'>Cerca su Discogs</span>
                </SecondaryButton>
            </div>
            <Form submit={handleSubmit} noBtn={true}>
                <div className='mx-auto rounded-lg mt-5'>
                    <div className='grid gap-3 lg:grid-cols-4 grid-cols-1'>
                        <Input
                            error={form.errors.barcode}
                            value={form.data.barcode}
                            onChange={handleBarcodeChange}
                            label='Codice a barre'
                            id='barcode'
                        />

                        <Input
                            error={form.errors.cat_number}
                            value={form.data.cat_number}
                            onChange={inputChange}
                            label='Numero catalogo'
                            id='cat_number'
                        />

                        <Input
                            error={form.errors.release_id}
                            value={form.data.release_id}
                            onChange={inputChange}
                            label='ID / Link Release'
                            id='release_id'
                        />

                        <Combo
                            items={formats}
                            error={form.errors.format_id}
                            label={'Formato'}
                            displayValue={'name'}
                            onChange={format => {
                                if (format) {
                                    form.setData(prev => ({ ...prev, format_id: parseInt(format.id) }));
                                }
                            }}
                            selected={formats.find(format => format.id === form.data.format_id) || null}
                        />
                    </div>

                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <div>
                            <Autocomplete
                                routeName='artist.index'
                                label='Artista'
                                initialValue={form.data.artist || undefined}
                                value={form.data.artist || undefined}
                                error={form.errors.artist_id}
                                placeholder="Digita l'Artista"
                                allowCreate={true}
                                onChange={artist => {
                                    if (
                                        artist &&
                                        typeof artist === 'object' &&
                                        artist.id !== undefined &&
                                        artist.id !== null
                                    ) {
                                        form.setData(prev => ({
                                            ...prev,
                                            artist_id: parseInt(artist.id as string),
                                            artist: artist,
                                            artist_name: artist.id === 0 ? artist.name : '',
                                        }));
                                        setDiscogsNotices(prev => ({ ...prev, artist: undefined }));
                                    }
                                }}
                                onClear={() => {
                                    form.setData(prev => ({
                                        ...prev,
                                        artist_id: null,
                                        artist: null,
                                        artist_name: '',
                                    }));
                                }}
                            />
                            {discogsNotices.artist && (
                                <div className='mt-1 text-sm text-white bg-red-500 border border-red-500 px-2 py-1 -translate-y-[10px]'>
                                    {`L'artista "${discogsNotices.artist}" non è stato trovato, selezionane uno esistente
                                    altrimenti quello importato sarà creato al salvataggio`}
                                </div>
                            )}
                        </div>

                        <Input
                            error={form.errors.title}
                            value={form.data.title}
                            onChange={inputChange}
                            label='Titolo'
                            id='title'
                        />

                        <div>
                            <Autocomplete
                                routeName='label.index'
                                label='Etichetta'
                                initialValue={form.data.label || undefined}
                                value={form.data.label || undefined}
                                error={form.errors.label_id}
                                placeholder="Digita l'Etichetta"
                                allowCreate={true}
                                onChange={label => {
                                    if (
                                        label &&
                                        typeof label === 'object' &&
                                        label.id !== undefined &&
                                        label.id !== null
                                    ) {
                                        form.setData(prev => ({
                                            ...prev,
                                            label_id: parseInt(label.id as string),
                                            label: label,
                                            label_name: label.id === 0 ? label.name : '',
                                        }));
                                        setDiscogsNotices(prev => ({ ...prev, label: undefined }));
                                    }
                                }}
                                onClear={() => {
                                    form.setData(prev => ({
                                        ...prev,
                                        label_id: null,
                                        label: null,
                                        label_name: '',
                                    }));
                                }}
                            />
                            {discogsNotices.label && (
                                <div className='mt-1 text-sm text-white bg-red-500 border border-red-500 px-2 py-1 -translate-y-[10px]'>
                                    {`L'etichetta "${discogsNotices.label}" non è stata trovata, selezionane una esistente
                                    altrimenti quella importata sarà creata al salvataggio`}
                                </div>
                            )}
                        </div>
                    </div>

                    <div className='grid gap-3 lg:grid-cols-3 grid-cols-1'>
                        <CurrencyInput
                            error={form.errors.retail_price}
                            onChange={inputChange}
                            name='retail_price'
                            id='retail_price'
                            label='Prezzo al dettaglio'
                        />

                        <CurrencyInput
                            error={form.errors.wholesale_price}
                            value={form.data.wholesale_price}
                            onChange={inputChange}
                            name='wholesale_price'
                            id='wholesale_price'
                            label='Prezzo all’ingrosso'
                        />

                        <CurrencyInput
                            error={form.errors.purchase_price}
                            onChange={inputChange}
                            name='purchase_price'
                            id='purchase_price'
                            label='Prezzo di acquisto'
                        />
                    </div>

                    <div className='grid gap-3 lg:grid-cols-3 grid-cols-1'>
                        <Combo
                            items={types}
                            error={form.errors.type}
                            label={'Nuovo/Usato'}
                            displayValue={'description'}
                            onChange={type => {
                                if (type) {
                                    form.setData(prev => ({
                                        ...prev,
                                        type: type.value,
                                        ...(type.value === 'new'
                                            ? { disk_status: 0, cover_status: 0 }
                                            : { disk_status: null as any, cover_status: null as any }),
                                    }));
                                }
                            }}
                        />

                        <Combo
                            items={diskstatus}
                            error={form.errors.disk_status}
                            label={'Condizione Disco'}
                            displayValue={'description'}
                            selected={diskstatus.find(ds => ds.value === form.data.disk_status)}
                            onChange={disk_status => {
                                if (disk_status) {
                                    form.setData(prev => ({
                                        ...prev,
                                        disk_status: parseInt(disk_status.value),
                                    }));
                                }
                            }}
                        />

                        <Combo
                            items={coverstatus}
                            error={form.errors.cover_status}
                            label={'Condizione Copertina'}
                            displayValue={'description'}
                            selected={coverstatus.find(cs => cs.value === form.data.cover_status)}
                            onChange={cover_status => {
                                if (cover_status) {
                                    form.setData(prev => ({
                                        ...prev,
                                        cover_status: parseInt(cover_status.value),
                                    }));
                                }
                            }}
                        />
                    </div>

                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <TextAreaInput
                            error={form.errors.description}
                            value={form.data.description}
                            onChange={inputChange}
                            label='Descrizione'
                            id='description'
                        />

                        <TextAreaInput
                            error={form.errors.comments}
                            value={form.data.comments}
                            onChange={inputChange}
                            label='Commenti'
                            id='comments'
                        />

                        <Input
                            error={form.errors.location_text}
                            value={form.data.location_text}
                            onChange={inputChange}
                            label='Nome Location'
                            id='location_text'
                        />

                        <Combo
                            items={forsalediscogsstatus}
                            error={form.errors.for_sale_on_discogs}
                            label={'Su Discogs'}
                            displayValue={'description'}
                            onChange={for_sale_on_discogs => {
                                if (for_sale_on_discogs) {
                                    form.setData(prev => ({
                                        ...prev,
                                        for_sale_on_discogs: parseInt(for_sale_on_discogs.value),
                                    }));
                                }
                            }}
                        />
                    </div>
                </div>

                <div className='flex mb-5'>
                    <StockList
                        items={form.data.stock}
                        areas={areas}
                        onStocksUpdate={handleStocksUpdate}
                        createMode={true}
                        // total_stocks={total_stocks}
                    />
                </div>

                <FormSection>
                    <div className='col-span-2 h-auto pb-5 mb-5'>
                        <Label className='block text-sm font-normal leading-6 text-gray-500 mb-2'>{'File'}</Label>

                        {form.data.discogs_image_url && (
                            <div className='mb-4 p-4 border bg-gray-50'>
                                <Label className='block text-sm font-medium mb-2'>
                                    Immagine da Discogs (sarà salvata quando durante creazione record)
                                </Label>
                                <div className='flex justify-between items-end gap-4'>
                                    <img
                                        src={form.data.discogs_image_url}
                                        alt='Discogs cover'
                                        className='w-24 h-24 object-cover rounded border'
                                    />
                                    <Button
                                        size='sm'
                                        type='button'
                                        onClick={() => form.setData('discogs_image_url', '')}
                                        className='bg-destructive hover:bg-destructive/90'>
                                        <Trash className='w-4 h-4 mr-1' />
                                        Rimuovi immagine Discogs
                                    </Button>
                                </div>
                            </div>
                        )}

                        <div>
                            <Dropzone onChange={file => onFileChange(file)} label='Drag and drop files' />
                        </div>
                        <div className='relative'>
                            {form.data.media_upload && form.data.media_upload.length > 0 && (
                                <div className='my-2 items-center bg-green-100 border border-green-300 center flex flex-row relative inline-block select-none whitespace-nowrap rounded-lg align-baseline font-sans text-green-700 text-sm leading-none overflow-hidden'>
                                    <div className='relative w-12 h-12 items-center content-center justify-items-center'>
                                        <FileText className='w-6 h-6' />
                                    </div>
                                    <div className='p-2 opacity-50'>
                                        <div className='w-6 h-6'></div>
                                    </div>
                                    <p className='flex-1 px-2 h-full flex justify-left items-center py-1 g border-r border-green-300'>
                                        <span>
                                            <span className='d-block'>{form.data.media_upload[0].name}</span>
                                            <br />
                                            <span className='d-block pt-2 text-xs uppercase'>
                                                Salva per caricare questo media
                                            </span>
                                        </span>
                                    </p>

                                    <Button
                                        variant={'ghost'}
                                        size={'icon'}
                                        className={'h-[30px] text-green-700 hover:text-red-500 hover:text-red-500'}
                                        onClick={() => onFileDelete(form.data.media_upload[0])}>
                                        <Trash className='w-5 h-5' />
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </FormSection>

                <div className='flex justify-end'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('record.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Crea'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
