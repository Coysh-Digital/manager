<?php

declare(strict_types=1);

/*
 * What each capability means, in plain words.
 *
 * These strings are the honest answer to "what does granting this actually let Manager do to my
 * website". They are shown next to the switch, not buried in documentation, because the moment
 * somebody is deciding is the moment they need to know.
 *
 * Say what it permits, and say what it does not.
 */

return [

    'inventory:read' => [
        'title' => 'Read status and versions',
        'description' => 'Lets the fleet table show Craft and plugin versions, PHP version, queue and migration counts and licence state, without anyone opening the control panel. It cannot change anything on the website, and it reads no entries, assets or user records.',
    ],

    'updates:read' => [
        'title' => 'Check for available updates',
        'description' => 'Compares installed versions against published releases so security updates can be flagged. Reports what is available; it cannot install anything.',
    ],

    'licences:read' => [
        'title' => 'Read licence state',
        'description' => 'Reports whether Craft and plugin licences are valid, on trial or expiring. Licence keys themselves are never transmitted — only the state calculated on the site.',
    ],

    'security:read' => [
        'title' => 'Read security findings',
        'description' => 'Reports safe configuration booleans, such as whether dev mode is on or HTTPS is enforced. It reads no configuration values and no environment variables.',
    ],

    'system:read' => [
        'title' => 'Read system health',
        'description' => 'Reports database engine and version, queue depth and pending migrations. Counts only: job payloads are never read, because they would carry site content.',
    ],

    'backups:create' => [
        'title' => 'Take a database backup',
        'description' => 'Lets Manager take and verify scheduled database backups. This reads the full database, including user records, so it is granted separately and never during ordinary pairing. Backups are encrypted before they leave the server. It cannot restore or delete data.',
    ],

];
