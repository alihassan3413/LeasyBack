{{--
    Damage photos for one position.

    A nested table rather than inline images in a div: dompdf measures inline
    image flow inside a table cell unreliably and prints the photos on top of
    the last line of the description above them. Table cells have a defined
    height in dompdf, so chunking the photos into explicit rows of two makes the
    block's height exact and the overlap impossible.

    Both dimensions come from the renderer, computed from each photo's own
    pixels, so a photo is never stretched.

    @param  array<int, array{src: string, width: int, height: int}>  $images
    @param  string  $label
--}}
@if (count($images))
    <table class="thumbs">
        @foreach (array_chunk($images, 2) as $row)
            <tr>
                @foreach ($row as $image)
                    <td>
                        <img src="{{ $image['src'] }}"
                             width="{{ $image['width'] }}"
                             height="{{ $image['height'] }}"
                             alt="Schadenbild zu {{ $label }}">
                    </td>
                @endforeach
                @if (count($row) === 1)
                    <td></td>
                @endif
            </tr>
        @endforeach
    </table>
@endif
