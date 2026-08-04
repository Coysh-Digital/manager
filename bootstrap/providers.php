<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\MailConfigurationServiceProvider;

return [
    AppServiceProvider::class,
    MailConfigurationServiceProvider::class,
];
