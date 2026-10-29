<?php

use App\Models\Affiliation;
use App\Models\Campus;
use App\Models\City;
use App\Models\Course;
use App\Models\DocumentTemplate;
use App\Models\GrantingParty;
use App\Models\Holiday;
use App\Models\HolidayRecurrence;
use App\Models\Internship;
use App\Models\InternshipRequest;
use App\Models\InternshipType;
use App\Models\User;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Search Engine
    |--------------------------------------------------------------------------
    |
    | This option controls the default search connection that gets used while
    | using Laravel Scout. This connection is used when syncing all models
    | to the search service. You should adjust this based on your needs.
    |
    | Supported: "algolia", "meilisearch", "typesense",
    |            "database", "collection", "null"
    |
    */

    'driver' => env('SCOUT_DRIVER', 'meilisearch'),

    /*
    |--------------------------------------------------------------------------
    | Index Prefix
    |--------------------------------------------------------------------------
    |
    | Here you may specify a prefix that will be applied to all search index
    | names used by Scout. This prefix may be useful if you have multiple
    | "tenants" or applications sharing the same search infrastructure.
    |
    */

    'prefix' => env('SCOUT_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue Data Syncing
    |--------------------------------------------------------------------------
    |
    | This option allows you to control if the operations that sync your data
    | with your search engines are queued. When this is set to "true" then
    | all automatic data syncing will get queued for better performance.
    |
    */

    'queue' => env('SCOUT_QUEUE', true),

    /*
    |--------------------------------------------------------------------------
    | Database Transactions
    |--------------------------------------------------------------------------
    |
    | This configuration option determines if your data will only be synced
    | with your search indexes after every open database transaction has
    | been committed, thus preventing any discarded data from syncing.
    |
    */

    'after_commit' => true,

    /*
    |--------------------------------------------------------------------------
    | Chunk Sizes
    |--------------------------------------------------------------------------
    |
    | These options allow you to control the maximum chunk size when you are
    | mass importing data into the search engine. This allows you to fine
    | tune each of these chunk sizes based on the power of the servers.
    |
    */

    'chunk' => [
        'searchable' => 500,
        'unsearchable' => 500,
    ],

    /*
    |--------------------------------------------------------------------------
    | Soft Deletes
    |--------------------------------------------------------------------------
    |
    | This option allows to control whether to keep soft deleted records in
    | the search indexes. Maintaining soft deleted records can be useful
    | if your application still needs to search for the records later.
    |
    */

    'soft_delete' => true,

    /*
    |--------------------------------------------------------------------------
    | Identify User
    |--------------------------------------------------------------------------
    |
    | This option allows you to control whether to notify the search engine
    | of the user performing the search. This is sometimes useful if the
    | engine supports any analytics based on this application's users.
    |
    | Supported engines: "algolia"
    |
    */

    'identify' => env('SCOUT_IDENTIFY', false),

    /*
    |--------------------------------------------------------------------------
    | Algolia Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Algolia settings. Algolia is a cloud hosted
    | search engine which works great with Scout out of the box. Just plug
    | in your application ID and admin API key to get started searching.
    |
    */

    'algolia' => [
        'id' => env('ALGOLIA_APP_ID', ''),
        'secret' => env('ALGOLIA_SECRET', ''),
        'index-settings' => [
            // 'users' => [
            //     'searchableAttributes' => ['id', 'name', 'email'],
            //     'attributesForFaceting'=> ['filterOnly(email)'],
            // ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Meilisearch Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Meilisearch settings. Meilisearch is an open
    | source search engine with minimal configuration. Below, you can state
    | the host and key information for your own Meilisearch installation.
    |
    | See: https://www.meilisearch.com/docs/learn/configuration/instance_options#all-instance-options
    |
    */

    'meilisearch' => [
        'host' => env('MEILISEARCH_HOST', 'http://localhost:7700'),
        'key' => env('MEILISEARCH_KEY'),
        'search_connect_timeout' => (float) env('MEILISEARCH_SEARCH_CONNECT_TIMEOUT', 1),
        'search_timeout' => (float) env('MEILISEARCH_SEARCH_TIMEOUT', 3),
        'operations_connect_timeout' => (float) env('MEILISEARCH_OPERATIONS_CONNECT_TIMEOUT', 10),
        'operations_timeout' => (float) env('MEILISEARCH_OPERATIONS_TIMEOUT', 300),
        'index-settings' => [
            Affiliation::class => [
                'searchableAttributes' => ['name', 'email', 'registration_number'],
                'filterableAttributes' => ['id', 'user_id', 'campus_id', 'course_id', 'type', 'is_active'],
                'sortableAttributes' => ['name', 'id'],
                'typoTolerance' => ['disableOnAttributes' => ['email', 'registration_number']],
            ],
            User::class => [
                'searchableAttributes' => ['name', 'cpf', 'formatted_cpf', 'email'],
                'filterableAttributes' => ['affiliation_scopes', 'cpf', 'email'],
                'typoTolerance' => ['disableOnAttributes' => ['cpf', 'formatted_cpf', 'email']],
                'sortableAttributes' => ['name', 'id'],
            ],
            Campus::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['id', 'deactivated_at', 'state'],
                'sortableAttributes' => ['name', 'id'],
            ],
            City::class => [
                'searchableAttributes' => ['name', 'ibge_code'],
                'filterableAttributes' => ['state'],
                'sortableAttributes' => ['name'],
            ],
            GrantingParty::class => [
                'searchableAttributes' => ['name', 'document_number', 'normalized_document_number', 'selection_label'],
                'filterableAttributes' => ['id', 'campus_id', 'city_id', 'state', 'is_active'],
                'typoTolerance' => ['disableOnAttributes' => ['document_number', 'normalized_document_number', 'selection_label']],
                'sortableAttributes' => ['name', 'id'],
            ],
            Course::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['id', 'campus_id', 'is_active'],
                'sortableAttributes' => ['name', 'id'],
            ],
            InternshipType::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['id', 'campus_id', 'course_id', 'is_active'],
                'sortableAttributes' => ['name', 'id'],
            ],
            DocumentTemplate::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['campus_id', 'is_active', 'document_type', 'remuneration'],
                'sortableAttributes' => ['name', 'id'],
            ],
            InternshipRequest::class => [
                'searchableAttributes' => ['student_name', 'registration_number', 'id'],
                'filterableAttributes' => ['campus_id', 'course_id', 'status'],
                'sortableAttributes' => ['updated_at', 'id'],
                'typoTolerance' => ['disableOnAttributes' => ['registration_number', 'id']],
            ],
            Internship::class => [
                'searchableAttributes' => ['student_name', 'registration_number', 'id', 'protocol_numbers'],
                'filterableAttributes' => ['id', 'campus_id', 'course_id', 'status', 'advisor_affiliation_id', 'advisor_user_id', 'advisor_campus_id', 'student_affiliation_id', 'has_pending_cancellation'],
                'sortableAttributes' => ['updated_at', 'id'],
                'typoTolerance' => ['disableOnAttributes' => ['registration_number', 'id', 'protocol_numbers']],
            ],
            Holiday::class => [
                'searchableAttributes' => ['name'],
                'filterableAttributes' => ['year', 'scope', 'state_code', 'city_id', 'origin'],
                'sortableAttributes' => ['date', 'name', 'id'],
            ],
            HolidayRecurrence::class => [
                'searchableAttributes' => ['name'],
                'sortableAttributes' => ['name', 'id'],
            ],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Typesense Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your Typesense settings. Typesense is an open
    | source search engine using minimal configuration. Below, you will
    | state the host, key, and schema configuration for the instance.
    |
    */

    'typesense' => [
        'client-settings' => [
            'api_key' => env('TYPESENSE_API_KEY', 'xyz'),
            'nodes' => [
                [
                    'host' => env('TYPESENSE_HOST', 'localhost'),
                    'port' => env('TYPESENSE_PORT', '8108'),
                    'path' => env('TYPESENSE_PATH', ''),
                    'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
                ],
            ],
            'nearest_node' => [
                'host' => env('TYPESENSE_HOST', 'localhost'),
                'port' => env('TYPESENSE_PORT', '8108'),
                'path' => env('TYPESENSE_PATH', ''),
                'protocol' => env('TYPESENSE_PROTOCOL', 'http'),
            ],
            'connection_timeout_seconds' => env('TYPESENSE_CONNECTION_TIMEOUT_SECONDS', 2),
            'healthcheck_interval_seconds' => env('TYPESENSE_HEALTHCHECK_INTERVAL_SECONDS', 30),
            'num_retries' => env('TYPESENSE_NUM_RETRIES', 3),
            'retry_interval_seconds' => env('TYPESENSE_RETRY_INTERVAL_SECONDS', 1),
        ],
        // 'max_total_results' => env('TYPESENSE_MAX_TOTAL_RESULTS', 1000),
        'model-settings' => [
            // User::class => [
            //     'collection-schema' => [
            //         'fields' => [
            //             [
            //                 'name' => 'id',
            //                 'type' => 'string',
            //             ],
            //             [
            //                 'name' => 'name',
            //                 'type' => 'string',
            //             ],
            //             [
            //                 'name' => 'created_at',
            //                 'type' => 'int64',
            //             ],
            //         ],
            //         'default_sorting_field' => 'created_at',
            //     ],
            //     'search-parameters' => [
            //         'query_by' => 'name'
            //     ],
            // ],
        ],
        'import_action' => env('TYPESENSE_IMPORT_ACTION', 'upsert'),
    ],

];
