<?php

declare(strict_types=1);
use coyshdigital\managerprotocol\Protocol;

it('loads the shared protocol package', function (): void {
    expect(class_exists(Protocol::class))->toBeTrue();
});
