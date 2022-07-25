<?php

namespace mwikala\linodes3;

use craft\events\RegisterComponentTypesEvent;
use craft\services\Fs as FsService;
use yii\base\Event;


/**
 * Plugin represents the Linode S3 volume plugin
 *
 * @author Mwikala Kangwa <hello@mwikala.co.uk>
 * @since 3.4
 */
class Plugin extends \craft\base\Plugin
{

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        Event::on(FsService::class, FsService::EVENT_REGISTER_FILESYSTEM_TYPES, static function (RegisterComponentTypesEvent $event) {
            $event->types[] = Fs::class;
        });
    }
}
