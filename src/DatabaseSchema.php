<?php

namespace Migration;

use Migration\Interfaces\SchemaInterface;

class DatabaseSchema implements SchemaInterface
{

    protected $schema;

    public function __construct(array $schema)
    {
        $this->schema = $this->parse($schema);
    }

    public function getSchema(): array
    {
        return $this->schema;
    }

    public function parse(array $schema): array
    {
        $tmpArray = [];

        foreach ($schema as $table => $fields) {
            foreach ($fields as $field) {
                if ($field['Field'] !== 'id') {
                    $tmpArray['entities'][$table][$field['Field']] = [
                        'type' => $field['Type'],
                        'required' => $field['Null'],
                        'default' => $field['Default'],
                    ];
                }
            }
        }

        return $tmpArray;
    }

    public function getTables(): array
    {
        $tmpArray = [];
        foreach ($this->schema['entities'] as $table => $columns) {
            $tmpArray[] = $table;
        }

        return $tmpArray;
    }

    public function getTableDescription(string $table): array
    {
        return $this->schema['entities'][$table];
    }


}