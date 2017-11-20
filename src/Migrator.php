<?php

namespace Migration;

class Migrator
{
    private $databaseSchema;
    private $entitiesSchema;
    private $connection;

    public function __construct(\PDO $connection, array $entitiesArray) {
        $this->connection = $connection;

        $this->entitiesSchema = new EntitiesSchema($entitiesArray);

        $databaseArray = [];

        try {
            $tables = $connection->query('SHOW TABLES');
            $tables = $tables->fetchAll();
            foreach ($tables as $table) {
                $description = $connection->query('DESC ' . $table[0]);
                $databaseArray[$table[0]] = $description->fetchAll();
            }

            $this->databaseSchema = new DatabaseSchema($databaseArray);
        } catch (\PDOException $e) {
            print "Erreur !: " . $e->getMessage() . "<br/>";
            die();
        }
    }

    public function setDatabaseSchema(DatabaseSchema $schema) {
        $this->databaseSchema = $schema;
    }

    public function setEntitiesSchema(EntitiesSchema $schema) {
        $this->entitiesSchema = $schema;
    }

    public function setSchemas(EntitiesSchema $entitiesSchema, DatabaseSchema $databaseSchema) {
        $this->databaseSchema = $databaseSchema;
        $this->entitiesSchema = $entitiesSchema;
    }

    public function compare() {

        $tmpArray = [];

        /**
         * Tables ajoutées ou modifiées
         */
        foreach ($this->entitiesSchema->getTables() as $table) {
            if (in_array($table, $this->databaseSchema->getTables())) {
                if (!empty($comparaison = $this->compareTableColumns($table))) {
                    if (!array_key_exists('updated', $tmpArray)) {
                        $tmpArray['updated'] = [];
                    }

                    $tmpArray['updated'][$table] = $comparaison;
                }
            } else {
                // Si la catégorie 'added' n'existe pas dans le tableau,
                // on la créé pour y mettre les tables nouvellement ajoutées
                if (!array_key_exists('added', $tmpArray)) {
                    $tmpArray['added'] = [];
                }

                $tmpArray['added'][$table] = $this->entitiesSchema->getTableDescription($table);
            }
        }

        /**
         * Tables supprimées
         */
        foreach ($this->databaseSchema->getTables() as $table) {
            if (!in_array($table, $this->entitiesSchema->getTables())) {
                if (!array_key_exists('removed', $tmpArray)) {
                    $tmpArray['removed'] = [];
                }
                $tmpArray['removed'][] = $table;
            }
        }

        return $tmpArray;
    }

    public function compareTableColumns(string $table) : array
    {
        /**
         * Contient la réponse de la méthode
         *
         * Ce tableau peut être constitué des clés ['added']['updated']
         */
        $tmpArray = [];
        /**
         * On récupère le schema de la table externe $table (celle que l'ont compare avec l'objet courant)
         * (NOTE : L'objet courant est considéré comme actualisé est sert donc de base de comparaison
         *  tandis que $table est considéré comme l'objet obselète.)
         *
         * Exemple de ce que $externalSchemaTable peut contenir :
         *
         * Array (
         *     first_name => Array(
         *         type => VARCHAR(25),
         *         null => YES,
         *         default =>
         *     ),
         *     email => Array(
         *         type => VARCHAR(50),
         *         null => YES,
         *         default =>
         *     )
         * )
         */
        $externalSchemaTable = $this->databaseSchema->getTableDescription($table);
        /**
         * Pour chaque colonne et sa description
         * On test si la colonne existe dans la description du schema obsolète (externe)
         *
         * Si la colonne existe, on regarde si une des clé de sa description à changé
         * On fait donc une boucle sur $description
         *
         * Exemple des clés de description : ($description contient)
         *
         * $description = Array(
         *     'type' => VARCHAR(255),
         *     'null' => YES,
         *     'default' =>
         * )
         *
         * Sinon si la colonne n'existe pas dans le schema obsolète,
         * on l'ajoute dans la partie 'added' de la réponse.
         */
        foreach ($this->entitiesSchema->getTableDescription($table) as $column => $description) {
            if (array_key_exists($column, $externalSchemaTable)) {
                foreach ($description as $key => $item) {
                    /**
                     * On test si la clé existe car par exemple, lors d'une relation
                     * Le schema des entités décris en fichier contient une clé 'entity'
                     * qui ne peut pas être comparé au schema de la db car cette clé n'existe pas en SQL
                     */
                    if (array_key_exists($key, $externalSchemaTable[$column]) && strtolower($externalSchemaTable[$column][$key]) !== strtolower($item)) {
                        /**
                         * Si la clé 'updated' n'existe pas encore, on la créée
                         */
                        if (!array_key_exists('updated', $tmpArray)) {
                            $tmpArray['updated'] = [];
                        }
                        /**
                         * On ajoute la description modifié à la réponse
                         */
                        $tmpArray['updated'][$column] = $description;
                    }
                }
            } else {
                /**
                 * Si la clé 'added' n'existe pas encore, on la créée
                 */
                if (!array_key_exists('added', $tmpArray)) {
                    $tmpArray['added'] = [];
                }
                /**
                 * On ajoute la colonne ajoutée à la réponse
                 */
                $tmpArray['added'][$column] = $description;
            }
        }

        /**
         * On cherche si des colonnes ont été supprimé dans le schéma de base
         * afin de pouvoir les supprimer dans le schema obsolète
         */
        foreach ($externalSchemaTable as $column => $description) {
            if ($column !== 'id') {
                if (!array_key_exists($column, $this->entitiesSchema->getTableDescription($table))) {

                    /**
                     * Si la clé 'removed' n'existe pas encore, on la créée
                     */
                    if (!array_key_exists('removed', $tmpArray)) {
                        $tmpArray['removed'] = [];
                    }

                    /**
                     * On ajoute la colonne supprimée à la réponse
                     */
                    $tmpArray['removed'][] = $column;
                }
            }
        }
        
        return $tmpArray;
    }

    public function migrate() {

        $changed = $this->compare();
        
        /**
         * On ajoute les tables manquantes
         */
        if (array_key_exists('added', $changed)) {
            foreach ($changed['added'] as $table => $description) {
                $this->createTable($table, $description);
            }
        }
        
        /**
         * On supprime les tables obsolètes
         */
        if (array_key_exists('removed', $changed)) {
            foreach ($changed['removed'] as $table) {
                $this->dropTable($table);
            }
        }
        
        /**
         * On met à jour les tables qui ont été modifié
         */
        if (array_key_exists('updated', $changed)) {
            foreach ($changed['updated'] as $table => $columns) {
                
                /**
                 * On ajoute les nouvelles colonnes
                 */
                if (array_key_exists('added', $columns)) {
                    foreach ($columns['added'] as $column => $description) {
                        $this->createColumn($table, $column, $description);
                    }
                }
                
                /**
                 * On supprime les colonnes obsolètes
                 */
                if (array_key_exists('removed', $columns)) {
                    foreach ($columns['removed'] as $column) {
                        $this->dropColumn($table, $column);
                    }
                }
                
                /**
                 * On met à jour les autres colonnes
                 */
                if (array_key_exists('updated', $columns)) {
                    foreach ($columns['updated'] as $column => $description) {
                        $this->updateColumn($table, $column, $description);
                    }
                }
            }
        }
        return $changed;
    }
    
    /**
     * Créée une table
     *
     * @param string $tableName
     * @param array $TableDescription
     */
    public function createTable(string $tableName, array $TableDescription)
    {
        $query = 'CREATE TABLE IF NOT EXISTS ' . $tableName . ' (';
        $query .= 'id INT NOT NULL AUTO_INCREMENT PRIMARY KEY, ';
        
        /**
         * Pour chaque colonne on ajoute sa description pour la création de la table
         */
        foreach ($TableDescription as $column => $description) {
            if ($description['required'] == 'YES') {
                $null = 'NULL';
            } else {
                $null = 'NOT NULL';
            }
            
            $query .= $column . ' ' . $description['type'] . ' ' . $null . ', ';
        }
        /**
         * On supprime la virgule et l'espace à la fin de la chaine de caractère
         */
        $query = substr($query, 0, -2);
        $query .= ')';
        print "Génération de la table [" . $tableName . "]\n\n";
        $this->connection->query($query);
    }

    /**
     * Supprime une table
     *
     * @param string $tableName
     */
    public function dropTable(string $tableName)
    {
        print "Suppression de la table [" . $tableName . "].\n\n";
        $this->connection->query('DROP TABLE . ' . $tableName);
    }

    /**
     * Ajoute une colonne à une table
     *
     * @param string $table
     * @param string $column
     * @param array  $description
     */
    public function createColumn(string $table, string $column, array $description)
    {
        if ($description['required'] == 'YES') {
            $null = 'NULL';
        } else {
            $null = 'NOT NULL';
        }

        $this->connection->query('ALTER TABLE '
                . $table
                . ' ADD '
                . $column
                . ' '
                . $description['type']
                . ' '
                . $null);

        print "Création du champ [" . $table . "][" . $column . "].\n\n";
    }

    /**
     * Supprime une colonne d'une table
     *
     * @param string $table
     * @param string $column
     */
    public function dropColumn(string $table, string $column)
    {
        print "Suppression de la colonne [" . $table . "][" . $column . "].\n\n";
        $this->connection->query('ALTER TABLE ' . $table . ' DROP ' . $column);
    }

    /**
     * Met à jour les caractéristiques d'une colonne
     *
     * @param string $table
     * @param string $column
     * @param array $description
     */
    public function updateColumn(string $table, string $column, array $description)
    {
        print "Mise à jour de la colonne [" . $table . "][" . $column . "].\n\n";

        if ($description['required'] == 'YES') {
            $null = 'NULL';
        } else {
            $null = 'NOT NULL';
        }

        $this->connection->query('ALTER TABLE '
                . $table
                . ' MODIFY '
                . $column
                . ' '
                . $description['type']
                . ' '
                . $null);
    }
}