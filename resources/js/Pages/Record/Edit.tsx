import Authenticated from '@/Layouts/AuthenticatedLayout';
import { useForm, router } from '@inertiajs/react';
import Form from '@/Components/atomica/Forms/Form';
import Input from '@/Components/atomica/Forms/Input';
import PrimaryButton from '@/Components/PrimaryButton';
import SecondaryButton from '@/Components/SecondaryButton';
import Helpers from '@/Components/atomica/Utils/Helpers';
import { Record, Stock } from '@/types';
import CurrencyInput from '@/Components/atomica/Forms/CurrencyInput';
import Combo from '@/Components/atomica/Utils/Combo';
import Autocomplete from '@/Components/atomica/Utils/Autocomplete';
import TextAreaInput from '@/Components/atomica/Forms/TextAreaInput';
import StockList from './Components/StockList';
import { useState, useEffect, useCallback, useRef } from 'react';
import { Label } from '@/Components/ui/label';
import { Button } from '@/Components/ui/button';
import FormSection from '@/Components/atomica/Forms/FormSection';
import Dropzone from '@/Components/atomica/Forms/Dropzone';
import { Search, Download, Eye, Printer, Trash, FileText, ExternalLink, Info, History } from 'lucide-react';
import Modal from '@/Components/atomica/Utils/Modal';
import DiscogsSearchResult from './Components/DiscogsSearchResult';
import axios from 'axios';
import useUnsavedChanges from '@/Hooks/useUnsavedChanges';
import { useCleanBarcodeInput } from '@/Hooks/useCleanBarcodeInput';
import DiscogsZeroPriceModal from '@/Components/records/DiscogsZeroPriceModal';
import { isZeroPrice } from '@/lib/utils';

export default function Edit({
    record,
    types,
    formats,
    diskstatus,
    coverstatus,
    forsalediscogsstatus,
    areas,
    editableAreaIds,
    stocks,
    listingData,
    errors,
}: {
    record: Record;
    types: Array<any>;
    formats: Array<any>;
    diskstatus: Array<any>;
    coverstatus: Array<any>;
    forsalediscogsstatus: Array<any>;
    areas: Array<any>;
    editableAreaIds: number[] | null;
    stocks: Array<any>;
    listingData: any;
    errors?: any;
}) {
    const form = useForm({ ...record, stock: stocks, _method: 'PATCH' });

    const isUpdatingFromServer = useRef(false);

    // Auto-fill barcode with rr_uid when both barcode and cat_number are empty
    useEffect(() => {
        if (!record.barcode && !record.cat_number && record.rr_uid) {
            form.setData('barcode', record.rr_uid);
        }
    }, []);

    const inputChange = Helpers.inputChange(form.setData);
    const submit = Helpers.submitForm(form)('record', record.id);

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
        if (file.id) {
            // FileMedia
            form.setData(prev => ({
                ...prev,
                media: form.data.media.filter((item: any) => item.id !== file.id),
            }));
        } else {
            // File
            form.setData(prev => ({
                ...prev,
                media_upload: form.data.media_upload.filter((item: File) => item.name !== file.name),
            }));
        }
    }

    function onFileChange(file: File) {
        form.setData(prev => ({
            ...prev,
            media: [],
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

                            // const matchingArtist = null;

                            const matchingLabel = discogsData.label
                                ? labels.find((l: any) => l.name.toLowerCase() === discogsData.label.toLowerCase())
                                : null;
                            // const matchingLabel = null;

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

    const [showListingModal, setShowListingModal] = useState(false);

    const handleShowListingModal = () => {
        setShowListingModal(true);
    };

    const handleCloseListingModal = () => {
        setShowListingModal(false);
    };

    const { isDirty, setIsDirty } = useUnsavedChanges({
        formData: form.data,
        initialData: { ...record, stock: stocks },
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
            editableFields: ['area_id', 'quantity', 'description'],
            numericFields: ['area_id', 'quantity'],
        },
    });

    // Update snapshot after save
    useEffect(() => {
        if (form.wasSuccessful) {
            setIsDirty(false);
            setDiscogsNotices({});

            // confirm that we are updating from the server
            isUpdatingFromServer.current = true;

            // Update form.data.stock with new stocks from the server
            form.setData('stock', stocks);

            // Reset del flag dopo un breve delay
            setTimeout(() => {
                isUpdatingFromServer.current = false;
            }, 100);
        }
    }, [form.wasSuccessful, setIsDirty, stocks]);

    // Sync stock only when it changes from the server (not from the user)
    useEffect(() => {
        if (!isUpdatingFromServer.current && stocks !== form.data.stock) {
            isUpdatingFromServer.current = true;
            form.setData('stock', stocks);

            setTimeout(() => {
                isUpdatingFromServer.current = false;
            }, 100);
        }
    }, [stocks]);

    const handleStocksUpdate = (updatedStocks: Stock[]) => {
        // update only if not updating from server
        if (!isUpdatingFromServer.current) {
            form.setData('stock', updatedStocks);
        }
    };

    return (
        <Authenticated title={'Modifica Record - ' + record.rr_uid}>
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

            {listingData && (
                <Modal type='primary' show={showListingModal} onClose={handleCloseListingModal} maxWidth='4xl'>
                    <h3 className='text-lg font-medium text-gray-900 mb-4'>Discogs Listing Raw Data</h3>

                    <div className='bg-gray-50 p-4 rounded-lg overflow-auto max-h-96 max-w-full'>
                        <pre className='text-sm text-gray-800 whitespace-pre-wrap'>
                            {JSON.stringify(listingData, null, 2)}
                        </pre>
                    </div>
                </Modal>
            )}

            <div className='flex justify-end gap-1'>
                <SecondaryButton className='-translate-y-full mt-1' onClick={() => searchOnDiscogs()}>
                    <Search className='h-4 w-4' />
                    <span className='hidden sm:block ms-2'>Cerca su Discogs</span>
                </SecondaryButton>
                <SecondaryButton
                    className='-translate-y-full mt-1'
                    onClick={() => {
                        window.open(route('record.barcode', record.id), '_blank');
                    }}>
                    <Printer className='h-4 w-4' />
                    <span className='hidden sm:block ms-2'>Stampa Barcode</span>
                </SecondaryButton>
                <SecondaryButton
                    className='-translate-y-full mt-1'
                    onClick={() => {
                        router.visit(route('record.history', record.id));
                    }}>
                    <History className='h-4 w-4' />
                    <span className='hidden sm:block ms-2'>Storico</span>
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

                        <div>
                            <Input
                                error={form.errors.release_id}
                                value={form.data.release_id}
                                onChange={inputChange}
                                label='ID / Link Release'
                                id='release_id'
                            />
                            {discogsNotices.release_id && (
                                <div className='mt-1 text-sm text-white bg-red-500 border border-red-500 px-2 py-1'>
                                    This release "{discogsNotices.release_id}" has not been found, try searching a
                                    different one
                                </div>
                            )}
                        </div>

                        <Combo
                            items={formats}
                            selected={formats.find(format => format.id === form.data.format_id) || null}
                            error={form.errors.format}
                            label={'Formato'}
                            displayValue={'name'}
                            onChange={format => {
                                if (format) {
                                    form.setData(prev => ({ ...prev, format_id: parseInt(format.id) }));
                                }
                            }}
                        />
                    </div>

                    <div className='grid gap-3 lg:grid-cols-2 grid-cols-1'>
                        <div>
                            <Autocomplete
                                routeName='artist.index'
                                label='Artista'
                                initialValue={form.data.artist || undefined}
                                value={form.data.artist || undefined}
                                error={form.errors.artist}
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
                                            artist_name: artist.id === 0 ? artist.name : '',
                                        }));
                                        setDiscogsNotices(prev => ({ ...prev, artist: undefined }));
                                    }
                                }}
                                onClear={() => {
                                    form.setData(prev => ({
                                        ...prev,
                                        artist_id: null,
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
                                error={form.errors.label}
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
                                            label_name: label.id === 0 ? label.name : '',
                                        }));
                                        setDiscogsNotices(prev => ({ ...prev, label: undefined }));
                                    }
                                }}
                                onClear={() => {
                                    form.setData(prev => ({
                                        ...prev,
                                        label_id: null,
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
                            value={form.data.retail_price}
                        />

                        <CurrencyInput
                            error={form.errors.wholesale_price}
                            onChange={inputChange}
                            name='wholesale_price'
                            id='wholesale_price'
                            label='Prezzo all’ingrosso'
                            value={form.data.wholesale_price}
                        />

                        <CurrencyInput
                            error={form.errors.purchase_price}
                            onChange={inputChange}
                            name='purchase_price'
                            id='purchase_price'
                            label='Prezzo di acquisto'
                            value={form.data.purchase_price}
                        />
                    </div>

                    <div className='grid gap-3 lg:grid-cols-3 grid-cols-1'>
                        <Combo
                            items={types}
                            error={form.errors.type}
                            label={'Nuovo/Usato'}
                            displayValue={'description'}
                            selected={types.find(t => t.value === form.data.type)}
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
                            value={form.data.location_text || ''}
                            onChange={inputChange}
                            label='Nome Location'
                            id='location_text'
                        />

                        <div>
                            <Combo
                                items={forsalediscogsstatus}
                                error={form.errors.for_sale_on_discogs}
                                label={'Su Discogs'}
                                displayValue={'description'}
                                selected={forsalediscogsstatus.find(fds => fds.value === form.data.for_sale_on_discogs)}
                                onChange={for_sale_on_discogs => {
                                    if (for_sale_on_discogs) {
                                        form.setData(prev => ({
                                            ...prev,
                                            for_sale_on_discogs: parseInt(for_sale_on_discogs.value),
                                        }));
                                    }
                                }}
                            />
                            {record.discogs_id && (
                                <div className='mb-4 p-3 bg-green-50 border border-green-200 rounded-md'>
                                    <p className='text-sm text-green-700'>
                                        <strong>Listed on Discogs:</strong> ID {record.discogs_id}
                                        <div className='mt-1'>
                                            <Button
                                                size='sm'
                                                type='button'
                                                variant='outline'
                                                onClick={() => {
                                                    window.open(
                                                        `https://www.discogs.com/sell/item/${record.discogs_id}`,
                                                        '_blank',
                                                    );
                                                }}>
                                                <ExternalLink className='mr-2 h-4 w-4' />
                                                View Listing
                                            </Button>
                                            {listingData && (
                                                <Button
                                                    size='sm'
                                                    type='button'
                                                    variant='outline'
                                                    onClick={handleShowListingModal}
                                                    className='ms-2'>
                                                    <Info className='mr-2 h-4 w-4' />
                                                    Listing Info
                                                </Button>
                                            )}
                                        </div>
                                    </p>
                                </div>
                            )}
                        </div>
                    </div>
                </div>

                <div className='flex mb-5'>
                    <StockList
                        items={form.data.stock}
                        record_id={record.id}
                        areas={areas}
                        editableAreaIds={editableAreaIds}
                        total_stocks={record.total_stocks ?? 0}
                        onStocksUpdate={handleStocksUpdate}
                        // total_stocks={total_stocks}
                    />
                </div>

                <FormSection>
                    <div className='col-span-2 h-auto pb-5 mb-5'>
                        <Label className='block text-sm font-medium leading-6 text-gray-900 mb-2'>{'File'}</Label>

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
                                        <Trash className='mr-2' />
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

                            {form.data.media && form.data.media.length > 0 && (
                                <div className='my-2 items-center bg-green-100 border border-green-300 center flex flex-row relative inline-block select-none whitespace-nowrap rounded-lg align-baseline font-sans text-green-700 text-sm leading-none overflow-hidden'>
                                    <div className='relative my-2 items-center content-center justify-items-center'>
                                        {form.data.media[0].mime_type &&
                                            form.data.media[0].mime_type.startsWith('image/') && (
                                                <div className='ml-2 mr-2 h-20 flex items-center'>
                                                    <img
                                                        src={route('media.show', form.data.media[0].id)}
                                                        alt={form.data.media[0].name}
                                                        className='max-h-full rounded object-contain'
                                                    />
                                                </div>
                                            )}
                                    </div>
                                    <div className='p-2 opacity-50'>
                                        <div className='w-6 h-6'></div>
                                    </div>
                                    <p className='flex-1 px-2 h-full flex justify-left items-center py-1 g border-r border-green-300'>
                                        <span>
                                            <span className='d-block'>{form.data.media[0].file_name}</span>
                                            <br />
                                        </span>
                                    </p>

                                    <Button
                                        variant={'ghost'}
                                        size={'icon'}
                                        className={'h-[30px] text-green-700 hover:text-red-500 hover:text-red-500'}
                                        onClick={e => {
                                            e.preventDefault();
                                            window.open(route('media.show', form.data.media[0].id), '_blank');
                                        }}>
                                        <Eye className='w-5 h-5' />
                                    </Button>

                                    <Button
                                        variant={'ghost'}
                                        size={'icon'}
                                        className={'h-[30px] text-green-700 hover:text-red-500 hover:text-red-500'}
                                        onClick={e => {
                                            e.preventDefault();
                                            window.open(route('media.download', form.data.media[0].id), '_blank');
                                        }}>
                                        <Download className='w-5 h-5' />
                                    </Button>

                                    <Button
                                        variant={'ghost'}
                                        size={'icon'}
                                        className={'h-[30px] text-green-700 hover:text-red-500 hover:text-red-500'}
                                        onClick={() => onFileDelete(form.data.media[0])}>
                                        <Trash className='w-5 h-5' />
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </FormSection>

                {errors.discogs_error && (
                    <div className='mb-4 p-4 border border-red-300 bg-red-50 rounded-md'>
                        <div className='flex'>
                            <div className='flex-shrink-0'>
                                <svg className='h-5 w-5 text-red-400' viewBox='0 0 20 20' fill='currentColor'>
                                    <path
                                        fillRule='evenodd'
                                        d='M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z'
                                        clipRule='evenodd'
                                    />
                                </svg>
                            </div>
                            <div className='ml-3'>
                                <h3 className='text-sm font-medium text-red-800'>Ci sono degli errori su Discogs</h3>
                                <div className='mt-2 text-sm text-red-700'>
                                    <p>{errors.discogs_error}</p>
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                <div className='flex justify-end'>
                    <SecondaryButton className='me-5' onClick={() => router.visit(route('record.index'))}>
                        {'Annulla'}
                    </SecondaryButton>
                    <PrimaryButton type={'submit'}>{'Aggiorna'}</PrimaryButton>
                </div>
            </Form>
        </Authenticated>
    );
}
