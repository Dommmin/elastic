<?php

/*
 * Generator danych seller-360 (ETAP 13, `seller360:simulate`).
 *
 * `cities_csv`: lista `miasto,wojewodztwo` — ta sama, z której search wylicza region
 * (`infra/reference/miasta-wojewodztwa.csv`, w kontenerach catalogu montowana pod /infra/reference).
 * `simulation_dir`: katalog na `planted.json` (prawda podstawowa dla dowodu z Task 12).
 */
return [
    'cities_csv' => env('SELLER360_CITIES_CSV', is_file('/infra/reference/miasta-wojewodztwa.csv')
        ? '/infra/reference/miasta-wojewodztwa.csv'
        : base_path('../../infra/reference/miasta-wojewodztwa.csv')),

    'simulation_dir' => storage_path('app/seller360'),
];
