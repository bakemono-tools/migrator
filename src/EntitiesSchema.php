<?php

namespace Migration;

use Migration\Interfaces\SchemaInterface;

class EntitiesSchema implements SchemaInterface
{
    protected $schema;

    public function __construct(array $schema)
    {
        $this->schema = $this->parse($schema);
    }

    public function parse(array $schema): array
    {
        if (array_key_exists('entities', $schema)) {

            /**
             * On modifie la description de chaque champ pour la rendre "presque" conforme au SQL.
             * Les dernières modifications se feront directement lors de la création des tables.
             */

            foreach ($schema['entities'] as &$entity) {
                foreach ($entity as $field => &$description) {
                    if ($field !== "timestamp") {
                        $description['type'] = $this::convertTypeToSQL($description['type']);

                        if (!array_key_exists('required', $description) || $description['required'] === true) {
                            $description['required'] = "NO";
                        } elseif ($description['required'] === false) {
                            $description['required'] = "YES";
                        } else {
                            throw new \Exception("La valeur pour l'option required de la propriété [" . $field . "] que vous avez saisie n'est pas un booléen.", 500);
                        }
                    } else {
                        unset($entity[$field]);

                        $entity['created_at'] = [
                            'type' => 'DATETIME',
                            'required' => true
                        ];

                        $entity['updated_at'] = [
                            'type' => 'DATETIME',
                            'required' => true
                        ];
                    }

                    if (!array_key_exists('default', $description)) {
                        $description['default'] = null;
                    }
                }
            }

            /**
             * On supprime la clé relation et on modifie le schema en conséquence
             */
            if (array_key_exists('relations', $schema)) {
                if (array_key_exists('oneHasOne', $schema['relations'])) {
                    foreach ($schema['relations']['oneHasOne'] as $relation) {
                        $schema['entities'][$relation['one1']][$relation['one2'] . '_id'] = [
                            'type' => 'int(11)',
                            'default' => null
                        ];
                        $schema['entities'][$relation['one2']][$relation['one1'] . '_id'] = [
                            'type' => 'int(11)',
                            'default' => null
                        ];

                        if (!array_key_exists('required', $relation) || $relation['required'] === true) {
                            $schema['entities'][$relation['one1']][$relation['one2'] . '_id']['required'] = "NO";
                            $schema['entities'][$relation['one2']][$relation['one1'] . '_id']['required'] = "NO";
                        } elseif ($relation['required'] === false) {
                            $schema['entities'][$relation['one1']][$relation['one2'] . '_id']['required'] = "YES";
                            $schema['entities'][$relation['one2']][$relation['one1'] . '_id']['required'] = "YES";
                        } else {
                            throw new \Exception("La valeur pour l'option required que vous avez saisie n'est pas un booléen.", 500);
                        }
                    }
                }

                if (array_key_exists('oneHasMany', $schema['relations'])) {
                    foreach ($schema['relations']['oneHasMany'] as $relation) {
                        $schema['entities'][$relation['many']][$relation['one'] . '_id'] = [
                            'type' => 'int(11)',
                            'default' => null
                        ];

                        if (!array_key_exists('required', $relation) || $relation['required'] === true) {
                            $schema['entities'][$relation['many']][$relation['one'] . '_id']['required'] = "NO";
                        } elseif ($relation['required'] === false) {
                            $schema['entities'][$relation['many']][$relation['one'] . '_id']['required'] = "YES";
                        } else {
                            throw new \Exception("La valeur pour l'option required que vous avez saisie n'est pas un booléen.", 500);
                        }
                    }
                }

                if (array_key_exists('manyHasMany', $schema['relations'])) {
                    foreach ($schema['relations']['manyHasMany'] as $relation) {
                        $schema['entities'][$relation['many1'] . '_' . $relation['many2']] = [
                            $relation['many1'] . '_id' => [
                                'type' => 'int(11)',
                                'required' => 'NO',
                                'default' => null
                            ],
                            $relation['many2'] . '_id' => [
                                'type' => 'int(11)',
                                'required' => 'NO',
                                'default' => null
                            ]
                        ];
                    }
                }

                unset($schema['relations']);
            }
        }

        return $schema;
    }

    public static function convertTypeToSQL(string $type): string {
        switch ($type) {
            case "word":
                return "varchar(25)";
                break;
            case "sentence":
                return "varchar(255)";
                break;
            case "text":
                return "text";
                break;
            case "integer":
                return "int(11)";
                break;
            default:
                return strtoupper($type);
        }
    }

    public function getSchema(): array {
        return $this->schema;
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