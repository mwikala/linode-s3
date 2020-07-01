<?php

namespace mwikala\linodes3;

use craft\events\RegisterComponentTypesEvent;
use craft\services\Volumes;
use yii\base\Event;


/**
 * Plugin represents the Linode S3 volume plugin
 * 
 * @author Mwikala Kangwa <support@mwikala.co.uk>
 * @since 3.4
 */
class Plugin extends \craft\base\Plugin
{

    /**
     * @inheritdoc
     */
    public function init()
    {
        parent::init();

        Event::on(Volumes::class, Volumes::EVENT_REGISTER_VOLUME_TYPES, function(RegisterComponentTypesEvent $event) {
            $event->types[] = Volume::class;
        });
    }
}