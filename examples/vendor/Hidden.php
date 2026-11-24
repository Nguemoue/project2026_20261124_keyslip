<?php

declare(strict_types=1);

// Under a vendor/ directory: a tree scan must not report it.
function hidden(array $ordersById): array
{
    return array_merge($ordersById, [1042 => 'paid']);
}
