@php($multi = $multi ?? false)
@php($priceFont = base64_encode(file_get_contents(resource_path('fonts/COOPBL.TTF'))))
@php($infoFont = base64_encode(file_get_contents(resource_path('fonts/NotoSans-Regular.ttf'))))
<style>
    @@font-face {
        font-family: 'Cooper Black';
        font-style: normal;
        font-weight: 400;
        src: url(data:font/truetype;charset=utf-8;base64,{{ $priceFont }}) format('truetype');
    }
    @@font-face {
        font-family: 'Noto Sans';
        font-style: normal;
        font-weight: 400;
        src: url(data:font/truetype;charset=utf-8;base64,{{ $infoFont }}) format('truetype');
    }
    @@page {
        size: 50mm 30mm;
        margin: 1mm;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: Arial, sans-serif;
        @unless($multi)
        width: 48mm;
        height: 28mm;
        overflow: hidden;
        @endunless
    }
    .label {
        width: 48mm;
        height: 28mm;
        display: flex;
        flex-direction: column;
        @if($multi)
        page-break-after: always;
        @endif
    }
    .top-row {
        display: flex;
        flex-direction: row;
        align-items: center;
        height: 16.5mm;
        flex-shrink: 0;
        width: 100%;
    }
    .logo-area {
        width: 16.5mm;
        flex-shrink: 0;
        display: flex;
        align-items: center;
    }
    .logo-area .barcode-logo {
        width: 100%;
        font-size: 6pt;
        font-weight: bold;
        text-transform: uppercase;
        text-align: center;
    }
    .price-area {
        flex: 1;
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-direction: column;
        height: 100%;
    }
    .price {
        font-family: 'Cooper Black', Georgia, serif;
        font-size: 18pt;
        line-height: 1;
        margin-left: auto;
        padding-bottom: 0;
        margin-bottom: auto;
    }
    .info-row {
        font-family: 'Noto Sans', Arial, sans-serif;
        display: flex;
        gap: 0.7mm;
        justify-items: center;
        align-items: end;
        font-size: 4.5pt;
        font-weight: 400;
        line-height: 1.4;
        text-transform: uppercase;
        padding: 0;
        overflow: hidden;
        flex-shrink: 0;
        width: 100%;
    }
    .info-row--new {
        flex-direction: column;
        align-items: stretch;
        gap: 0.5mm;
    }
    .info-top {
        display: flex;
        flex-direction: row;
        justify-content: space-between;
        width: 100%;
    }
    .info-item {
        width: fit-content;
        max-width: 50%;
        white-space: normal;
        word-break: break-word;
        overflow: hidden;
    }
    /* Secondhand: pin disk + cover condition to the right, whatever the description length */
    .info-row > .info-artist {
        margin-left: auto;
    }
    .info-row > .info-cover {
        margin-right: 2pt;
    }
    .info-title {
        max-width: 100%;
    }
    .barcode-row {
        flex: 1;
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: start;
        overflow: hidden;
        min-height: 0;
        padding-top: 0.5mm;
    }
    .barcode-row svg {
        display: block;
        width: 100%;
        max-height: 11mm;
        height: auto;
    }
    .barcode-row svg text {
        font-size: 12pt;
    }
    .barcode-number {
        font-size: 5pt;
        font-family: 'Noto Sans', Arial, sans-serif;
    }
    @@media print {
        body { margin: 0; }
    }
</style>
