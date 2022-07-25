<?php

namespace mwikala\linodes3\migrations;

use Craft;
use craft\db\Migration;
use craft\services\ProjectConfig;
use mwikala\linodes3\Fs;

class m220725_123900_update_linode_volume_to_filesystem extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $projectConfig = Craft::$app->getProjectConfig();

        $schemaVersion = $projectConfig->get('plugins.linodes3.schemaVersion', true);
        if (version_compare($schemaVersion, '2.0', '>=')) {
            return true;
        }

        $fsConfigs = $projectConfig->get(ProjectConfig::PATH_FS) ?? [];
        foreach ($fsConfigs as $uid => $config) {
            if ($config['type'] === 'mwikala\linodes3\Volume') {
                $config['type'] = Fs::class;
                $projectConfig->set(sprintf('%s.%s', ProjectConfig::PATH_FS, $uid), $config);
            }
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        echo "m220725_123900_update_linode_volume_to_filesystem cannot be reverted." . PHP_EOL;
        return false;
    }
}
