<?php

return [
    /*
    |--------------------------------------------------------------------------
    | SEO Location Data
    |--------------------------------------------------------------------------
    |
    | Centralized location data for SEO content generation and validation.
    | Used by ContentValidatorService and SchemaGeneratorService.
    |
    */

    'locations' => [
        'Portland' => [
            'state' => 'OR',
            'lat' => '45.5155',
            'lng' => '-122.6789',
        ],
        'Seattle' => [
            'state' => 'WA',
            'lat' => '47.6062',
            'lng' => '-122.3321',
        ],
        'Denver' => [
            'state' => 'CO',
            'lat' => '39.7392',
            'lng' => '-104.9903',
        ],
        'Austin' => [
            'state' => 'TX',
            'lat' => '30.2672',
            'lng' => '-97.7431',
        ],
        'San Francisco' => [
            'state' => 'CA',
            'lat' => '37.7749',
            'lng' => '-122.4194',
        ],
        'Los Angeles' => [
            'state' => 'CA',
            'lat' => '34.0522',
            'lng' => '-118.2437',
        ],
        'New York' => [
            'state' => 'NY',
            'lat' => '40.7128',
            'lng' => '-74.0060',
        ],
        'Chicago' => [
            'state' => 'IL',
            'lat' => '41.8781',
            'lng' => '-87.6298',
        ],
        'Boston' => [
            'state' => 'MA',
            'lat' => '42.3601',
            'lng' => '-71.0589',
        ],
        'Dallas' => [
            'state' => 'TX',
            'lat' => '32.7767',
            'lng' => '-96.7970',
        ],
        'Houston' => [
            'state' => 'TX',
            'lat' => '29.7604',
            'lng' => '-95.3698',
        ],
        'Phoenix' => [
            'state' => 'AZ',
            'lat' => '33.4484',
            'lng' => '-112.0740',
        ],
        'Atlanta' => [
            'state' => 'GA',
            'lat' => '33.7490',
            'lng' => '-84.3880',
        ],
        'Miami' => [
            'state' => 'FL',
            'lat' => '25.7617',
            'lng' => '-80.1918',
        ],
        'Minneapolis' => [
            'state' => 'MN',
            'lat' => '44.9778',
            'lng' => '-93.2650',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | State Abbreviations
    |--------------------------------------------------------------------------
    |
    | States that can be referenced in location pages.
    |
    */

    'states' => [
        'Oregon', 'Washington', 'Colorado', 'Texas', 'California',
    ],

    /*
    |--------------------------------------------------------------------------
    | Default Location
    |--------------------------------------------------------------------------
    |
    | Default location when none is specified (HQ).
    |
    */

    'default_location' => 'Portland',
];
