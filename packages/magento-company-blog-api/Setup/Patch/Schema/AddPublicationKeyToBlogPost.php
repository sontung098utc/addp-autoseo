<?php

namespace Company\BlogApi\Setup\Patch\Schema;

use Magento\Framework\DB\Ddl\Table;
use Magento\Framework\Setup\Patch\SchemaPatchInterface;
use Magento\Framework\Setup\SchemaSetupInterface;

class AddPublicationKeyToBlogPost implements SchemaPatchInterface
{
    private SchemaSetupInterface $schemaSetup;

    public function __construct(SchemaSetupInterface $schemaSetup)
    {
        $this->schemaSetup = $schemaSetup;
    }

    public function apply()
    {
        $this->schemaSetup->startSetup();
        $connection = $this->schemaSetup->getConnection();

        foreach (['magefan_blog_post', 'mageplaza_blog_post'] as $tableName) {
            $table = $this->schemaSetup->getTable($tableName);
            if ($connection->isTableExists($table) && !$connection->tableColumnExists($table, 'publication_key')) {
                $connection->addColumn(
                    $table,
                    'publication_key',
                    [
                        'type' => Table::TYPE_TEXT,
                        'length' => 128,
                        'nullable' => true,
                        'comment' => 'AutoSEO Idempotency Publication Key',
                    ]
                );
                $connection->addIndex(
                    $table,
                    $this->schemaSetup->getIdxName($tableName, ['publication_key']),
                    ['publication_key']
                );
            }
        }

        $this->schemaSetup->endSetup();

        return $this;
    }

    public static function getDependencies(): array
    {
        return [];
    }

    public function getAliases(): array
    {
        return [];
    }
}
