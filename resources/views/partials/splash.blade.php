{{-- iOS shows a blank screen while launching unless a startup image matches
     the device exactly, so there is one per screen class. --}}
@php
    $splashes = [
        ['1290x2796', 430, 932],
        ['1179x2556', 393, 852],
        ['1170x2532', 390, 844],
        ['1125x2436', 375, 812],
    ];
@endphp

@foreach ($splashes as [$file, $deviceWidth, $deviceHeight])
    <link
        rel="apple-touch-startup-image"
        media="screen and (device-width: {{ $deviceWidth }}px) and (device-height: {{ $deviceHeight }}px) and (-webkit-device-pixel-ratio: 3) and (orientation: portrait)"
        href="/splash/{{ $file }}.png"
    >
@endforeach
