<?php

namespace mwikala\linodes3;

use Craft;
use DateTime;
use Aws\S3\S3Client;
use craft\helpers\App;
use craft\helpers\Assets;
use Aws\Credentials\Credentials;
use craft\helpers\DateTimeHelper;
use craft\flysystem\base\FlysystemFs;
use Aws\Handler\GuzzleV6\GuzzleHandler;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use craft\behaviors\EnvAttributeParserBehavior;

/**
 * Class Volume
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Mwikala Kangwa <support@mwikala.co.uk>
 * @since 1.0
 */
class Fs extends FlysystemFs
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

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function behaviors(): array
    {
        $behaviors = parent::behaviors();
        $behaviors['parser'] = [
            'class' => EnvAttributeParserBehavior::class,
            'attributes' => [
                'subfolder', 'keyId', 'secret', 'region', 'bucket', 'endpoint'
            ],
        ];

        return $behaviors;
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            [['keyId', 'secret', 'region', 'bucket', 'endpoint'], 'required'],
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('linode-s3/fsSettings', [
            'fs' => $this,
            'periods' => array_merge(['' => ''], Assets::periodList()),
            'contentDispositionOptions' => [
                '' => 'none',
                'inline' => 'inline',
                'attachment' => 'attachment'
            ],
            'contentDisposition' => $this->contentDisposition
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        if (($rootUrl = parent::getRootUrl()) !== false && $this->_subfolder()) {
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
    protected function createAdapter(): FilesystemAdapter
    {
        $client = static::client($this->_getConfigArray(), $this->_getCredentials());

        return new AwsS3V3Adapter($client, App::parseEnv($this->bucket), $this->_subfolder(), null, null, [], false);
    }

    /**
     * Get the Amazon S3 Client
     *
     * @param $config
     * @return S3Client
     */
    protected static function client(array $config = [], array $credentials = []): S3Client
    {
        if (!empty($config['credentials']) && $config['credentials'] instanceof Credentials) {
            $config['generateNewConfig'] = static function() use ($credentials) {
                $args = [
                    $credentials['keyId'],
                    $credentials['secret'],
                    $credentials['region'],
                    true,
                ];

                return call_user_func_array(self::class . '::buildConfigArray', $args);
            };
        }

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
            $diff = (int) $expires->format('U') - (int) $now->format('U');
            $config['CacheControl'] = 'max-age=' . $diff . ', must-revalidate';
            $config['ContentDisposition'] = $this->contentDisposition;
        }

        return parent::addFileMetadataToConfig($config);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the parsed subfolder path
     *
     * @return string
     */
    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(App::parseEnv($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }

        return '';
    }

    /**
     * Get the config array for AWS client
     *
     * @return array
     */
    private function _getConfigArray()
    {
        $credentials = $this->_getCredentials();

        return self::_buildConfigArray($credentials['keyId'], $credentials['secret'], $credentials['region'], $credentials['endpoint']);
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
            'credentials' => [
                'key' => $keyId,
                'secret' => $secret,
            ],
        ];

        $client = Craft::createGuzzleClient();
        $config['http_handler'] = new GuzzleHandler($client);

        return $config;
    }

    /**
     * Return the credentials as an array
     *
     * @return array
     */
    private function _getCredentials(): array
    {
        return [
            'keyId' => App::parseEnv($this->keyId),
            'secret' => App::parseEnv($this->secret),
            'region' => App::parseEnv($this->region),
            'endpoint' => App::parseEnv($this->endpoint),
        ];
    }

    /** @inheritdoc */
    protected function invalidateCdnPath(string $path): bool
    {
        return true;
    }
}
