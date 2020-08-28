<?php

namespace mwikala\linodes3;

use Aws\Credentials\Credentials;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;

use Craft;
use craft\base\FlysystemVolume;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use League\Flysystem\AwsS3v3\AwsS3Adapter;

/**
 * Class Volume
 * 
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Mwikala Kangwa <support@mwikala.co.uk>
 * @since 1.0
 */
class Volume extends FlysystemVolume
{
    // Constants
    // =========================================================================

    const STORAGE_STANDARD = 'STANDARD';
    const STORAGE_REDUCE_REDUNDANCY = 'REDUCE_REDUNDANCY';
    const STORAGE_STANDARD_IA = 'STANDARD_IA';

    /**
     * Cache key to use for caching purposes
     */
    const CACHE_KEY_PREFIX = 'linode.';

    /**
     * Cache duration for access token
     */
    const CACHE_DURATION_SECONDS = 3600;

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Linode S3';
    }

    // Properties
    // =========================================================================

    /**
     * @var bool Whether this is a local source or not. Defaults to false.
     */
    protected $isVolumeLocal = false;

    /**
     * @var string Subfolder to use
     */
    public $subfolder = '';

    /**
     * @var string Access Key id
     */
    public $keyId = '';

    /**
     * @var string Secret Key
     */
    public $secret = '';

    /**
     * @var string Linode endpoint
     */
    public $endpoint = '';

    /**
     * @var string Bucket to use
     */
    public $bucket = '';

    /**
     * @var string Region to use
     */
    public $region = '';

    /**
     * @var string Cache expiration period
     */
    public $expires = '';

    /**
     * @var string Content Disposition value
     */ #
    public $contentDisposition = '';

    // Getters
    // =========================================================================

    public function getSubfolder()
    {
        return Craft::parseEnv($this->subfolder);
    }

    public function getKeyId()
    {
        return Craft::parseEnv($this->keyId);
    }

    public function getSecret()
    {
        return Craft::parseEnv($this->secret);
    }

    public function getEndpoint()
    {
        return Craft::parseEnv($this->endpoint);
    }

    public function getBucket()
    {
        return Craft::parseEnv($this->bucket);
    }

    public function getRegion()
    {
        return Craft::parseEnv($this->region);
    }

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function rules()
    {
        $rules = parent::rules();
        $rules[] = [['keyId', 'secret', 'region', 'bucket', 'endpoint'], 'required'];

        return $rules;
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml()
    {
        return Craft::$app->getView()->renderTemplate('linode-s3/volumeSettings', [
            'volume' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
            'contentDispositionOptions' => [
                '' => '--- none ---',
                'inline' => 'inline',
                'attachment' => 'attachment'
            ],
            'contentDisposition' => $this->contentDisposition
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl()
    {
        if (($rootUrl = parent::getRootUrl()) !== false && $this->getSubfolder()) {
            $rootUrl .= rtrim($this->getSubfolder(), '/') . '/';
        }
        return $rootUrl;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return AwsS3Adapter
     */
    protected function createAdapter()
    {
        $config = $this->_getConfigArray();

        $client = static::client($config);

        return new AwsS3Adapter($client, $this->getBucket(), $this->getSubfolder());
    }

    /**
     * Get the Amazon S3 Client
     * 
     * @param $config
     * @return S3Client
     */
    protected static function client(array $config = []): S3Client
    {
        return new S3Client($config);
    }

    /**
     * @inheritdoc
     */
    protected function addFileMetadataToConfig(array $config): array
    {
        if (!empty($this->expires) && DateTimeHelper::isValidIntervalString($this->expires)) {
            $expires = new DateTime();
            $now = new DateTime();
            $expires->modify('+' . $this->expires);
            $diff = $expires->format('U') - $now->format('U');
            $config['CacheControl'] = 'max-age=' . $diff . ', must-revalidate';
            $config['ContentDisposition'] = $this->contentDisposition;
        }

        return parent::addFileMetadataToConfig($config);
    }

    // Private Methods
    // =========================================================================

    /**
     * Get the config array for AWS client
     * 
     * @return array
     */
    private function _getConfigArray()
    {
        $keyId = $this->getKeyId();
        $secret = $this->getSecret();
        $region = $this->getRegion();
        $endpoint = $this->getEndpoint();

        return self::_buildConfigArray($keyId, $secret, $region, $endpoint);
    }

    /**
     * Built the config array
     * 
     * @param $keyId
     * @param $secret
     * @param $region
     * @param $endpoint
     * @return array
     */
    private static function _buildConfigArray($keyId = null, $secret = null, $region = null, $endpoint = null): array
    {
        $config = [
            'region' => $region,
            'endpoint' => $endpoint,
            'version' => 'latest',
            'credentials' => new Credentials(
                $keyId,
                $secret
            ),
        ];

        $client = Craft::createGuzzleClient();
        $config['http_handler'] = new GuzzleHandler($client);

        return $config;
    }
}
