<div class="label">
    <div class="top-row">
        <div class="logo-area">
            <span class="barcode-logo">logo cliente</span>
        </div>
        <div class="price-area">
            <span class="price">{{ $retail_price ?? '' }}</span>

            <div class="info-row">
                @if(($type ?? '') === 'secondhand')
                    <span class="info-item info-cat">{{ $cat_number ?? '' }}</span>
                    <span class="info-item info-artist">{{ $artist ?? '' }}</span>
                    <span class="info-item info-cover">{{ $cover_condition ?? '' }}</span>
                @else
                </div>
                <div class="info-row info-row--new">
                    <div class="info-top">
                        <span class="info-item info-cat">{{ $cat_number ?? '' }}</span>
                        <span class="info-item info-artist">{{ $artist ?? '' }}</span>
                    </div>
                    <span class="info-item info-title">{{ $title ?? '' }}</span>
                @endif
            </div>
        </div>
    </div>

    <div class="barcode-row">
        {!! $barcode_img !!}
        <div class="barcode-number">{{ $barcode ?? '' }}</div>
    </div>
</div>
