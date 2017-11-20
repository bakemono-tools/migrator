<?php

namespace Migration\Interfaces;

interface SchemaInterface
{
    public function __construct(array $schema);

    public function parse(array $schema): array;

    public function getSchema(): array;

    public function getTables(): array;

    public function getTableDescription(string $table): array;
}