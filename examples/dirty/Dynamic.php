<?php

declare(strict_types=1);

function byKey(array $rest, $id, string $row): array
{
    return array_merge([$id => $row], $rest);
}

function spreadDynamic(array $rest, $id, string $row): array
{
    return [...[$id => $row], ...$rest];
}
