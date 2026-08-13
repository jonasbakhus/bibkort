<?php

declare(strict_types=1);

// Kopiér denne fil til secrets.php på serveren. secrets.php ignoreres af Git.
return [
    'traveltime_app_id' => 'INDSÆT_APPLICATION_ID',
    'traveltime_api_key' => 'INDSÆT_APPLICATION_KEY',
    // Brug kun på det separate Google-testmiljø. Nøglen sendes aldrig til browseren.
    'variant' => 'standard',
    'google_isochrones_api_key' => 'INDSÆT_GOOGLE_MAPS_API_KEY',
];
