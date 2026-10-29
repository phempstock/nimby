<?php
/**
 * NIMBY configuration.
 *
 * Copy this file to config.php (in the project root, outside the web root)
 * and fill in the real values. config.php is ignored by git.
 */
return [
    // Database (IONOS: Hosting > Databases). MySQL and MariaDB both use 'mysql:'. Examples:
    //   mysql:host=db5000000000.hosting-data.io;dbname=dbs0000000;charset=utf8mb4
    //   sqlite:/path/to/nimby.sqlite   (local development only)
    'db' => [
        'dsn'      => 'mysql:host=localhost;dbname=nimby;charset=utf8mb4',
        'user'     => 'nimby',
        'password' => 'change-me',
    ],

    // Nottingham City Council ArcGIS services.
    'council' => [
        'planning_layer_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Planning/MapServer/13',
        'conservation_layer_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Core_services/Local_Plan_2020/MapServer/49',
        'conservation_area_name' => 'The Park',
        // Also watch applications within this many metres of the conservation area
        // (0 = the conservation area only). Agreed by the committee: 50.
        'buffer_metres' => 50,
        'geometry_service_url' => 'https://maps164.nottinghamcity.gov.uk/server/rest/services/Utilities/Geometry/GeometryServer',
        'public_access_url' => 'https://publicaccess.nottinghamcity.gov.uk/online-applications/applicationDetails.do?activeTab=summary&keyVal=',
        'timeout_seconds' => 60,
    ],

    // Where the sync job writes files the map page reads.
    'paths' => [
        'public_data' => __DIR__ . '/public/data',
        'cache'       => __DIR__ . '/data/cache',
    ],

    // Failure alerts from the sync job.
    'alerts' => [
        'admin_email' => 'nimby@parknews.co.uk',
        'from_email'  => 'nimby@parknews.co.uk',
    ],

    // Abort the sync (keeping yesterday's data) if the council returns fewer
    // than this share of the applications we already hold.
    'sync' => [
        'min_ratio_of_known' => 0.8,
    ],
];
