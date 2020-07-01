<?php

namespace mwikala\linodes3;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * Asset bundle for the Dashboard
 */
class LinodeS3Bundle extends AssetBundle
{
    /**
     * @inheritdoc
     */
    public function init()
    {
        $this->sourcePath = '@mwikala/linodes3/resources';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'js/editVolume.js'
        ];

        parent::init();
    }
}