@php
    $record = $getRecord();
    $url = $record->fileUrl();
@endphp

<div class="fi-media-preview" style="border-radius: 0.5rem; overflow: hidden; aspect-ratio: 1 / 1; background-color: rgb(15 23 42);">
    @if ($url)
        @if ($record->type === 'video')
            <video controls preload="metadata" style="width: 100%; height: 100%; object-fit: cover;">
                <source src="{{ $url }}">
            </video>
        @else
            <img src="{{ $url }}" alt="Portfolio photo" style="width: 100%; height: 100%; object-fit: cover;" />
        @endif
    @endif
</div>
