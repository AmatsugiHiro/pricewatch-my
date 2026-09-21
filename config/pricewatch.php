<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Upstream open data
    |--------------------------------------------------------------------------
    |
    | PriceCatcher is published by the Ministry of Domestic Trade and Cost of
    | Living (KPDN) through data.gov.my. Each monthly file is served as both
    | Parquet and CSV; we read the CSV so the pipeline needs no extra extension.
    |
    |   {base_url}/lookup_item.csv
    |   {base_url}/lookup_premise.csv
    |   {base_url}/pricecatcher_YYYY-MM.csv
    |
    */

    'base_url' => env('PRICEWATCH_BASE_URL', 'https://storage.data.gov.my/pricecatcher'),

    /*
    |--------------------------------------------------------------------------
    | Local staging directory
    |--------------------------------------------------------------------------
    |
    | Downloaded CSVs are cached here so a re-run does not re-download ~48 MB.
    |
    */

    'staging_path' => env('PRICEWATCH_STAGING_PATH', storage_path('app/pricecatcher')),

    /*
    |--------------------------------------------------------------------------
    | Ingestion tuning
    |--------------------------------------------------------------------------
    |
    | chunk_size is the number of rows per bulk upsert. With four columns per row
    | this stays far below MySQL's 65,535 placeholder ceiling while keeping the
    | number of round-trips low. Raising it trades memory for fewer statements.
    |
    */

    'chunk_size' => (int) env('PRICEWATCH_CHUNK_SIZE', 2000),

    'download_timeout' => (int) env('PRICEWATCH_DOWNLOAD_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Earliest period worth backfilling
    |--------------------------------------------------------------------------
    */

    'earliest_period' => env('PRICEWATCH_EARLIEST_PERIOD', '2022-01'),

];
