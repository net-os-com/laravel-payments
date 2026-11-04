<?php

namespace NetOS\Payments\Data;

use Spatie\LaravelData\Data;

class BrandingData extends Data
{
    public function __construct(
        /** Hex, e.g. #0A2D51. */
        public ?string $primaryColor = null,
        public ?string $secondaryColor = null,
        /** A readable path or URL of a PNG or JPG; uploaded to the provider. */
        public ?string $logoPath = null,
    ) {}
}
