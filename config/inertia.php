<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Testing
    |--------------------------------------------------------------------------
    |
    | Published for one load-bearing reason: the package default page path is
    | resources/js/Pages (capital P) while this app uses resources/js/pages.
    | macOS's case-insensitive filesystem hides the mismatch locally; Linux CI
    | does not — assertInertia() then fails with "component does not exist".
    |
    */

    'testing' => [
        'ensure_pages_exist' => true,
        'page_paths' => [resource_path('js/pages')],
        'page_extensions' => ['js', 'jsx', 'svelte', 'ts', 'tsx', 'vue'],
    ],

];
