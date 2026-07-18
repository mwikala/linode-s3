<?php

declare(strict_types=1);

namespace mwikala\linodes3;

use Aws\S3\S3Client;
use Craft;
use craft\behaviors\EnvAttributeParserBehavior;
use craft\flysystem\base\FlysystemFs;
use craft\helpers\App;
use craft\helpers\Assets;
use craft\helpers\DateTimeHelper;
use DateTime;
use League\Flysystem\FilesystemAdapter;

/**
 * Linode Object Storage filesystem.
 *
 * @property mixed $settingsHtml
 * @property string $rootUrl
 * @author Mwikala Kangwa <hello@mwikala.co.uk>
 * @since 1.0
 */
class Fs extends FlysystemFs
{
    // Constants
    // =========================================================================

    public const STORAGE_STANDARD = 'STANDARD';
    public const STORAGE_REDUCED_REDUNDANCY = 'REDUCED_REDUNDANCY';
    public const STORAGE_STANDARD_IA = 'STANDARD_IA';

    /**
     * Cache key to use for caching purposes.
     */
    public const CACHE_KEY_PREFIX = 'linode.';

    /**
     * Cache duration for access token.
     */
    public const CACHE_DURATION_SECONDS = 3600;

    // Static
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function displayName(): string
    {
        return 'Linode Object Storage';
    }

    // Properties
    // =========================================================================

    /**
     * @var bool Whether this is a local source or not. Defaults to false.
     */
    protected bool $isVolumeLocal = false;

    /**
     * @var string Subfolder to use.
     */
    public string $subfolder = '';

    /**
     * @var string Access key ID.
     */
    public string $keyId = '';

    /**
     * @var string Secret access key.
     */
    public string $secret = '';

    /**
     * @var string Linode Object Storage API endpoint.
     */
    public string $endpoint = '';

    /**
     * @var string Bucket to use.
     */
    public string $bucket = '';

    /**
     * @var string Region to use.
     */
    public string $region = '';

    /**
     * @var string Cache expiration period.
     */
    public string $expires = '';

    /**
     * @var string Content-Disposition value.
     */
    public string $contentDisposition = '';

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
                'subfolder',
                'keyId',
                'secret',
                'endpoint',
                'bucket',
                'region',
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
                'attachment' => 'attachment',
            ],
            'contentDisposition' => $this->contentDisposition,
        ]);
    }

    /**
     * @inheritdoc
     */
    public function getRootUrl(): ?string
    {
        if (($rootUrl = parent::getRootUrl()) !== false && $this->_subfolder()) {
            $rootUrl .= rtrim($this->_subfolder(), '/') . '/';
        }

        return $rootUrl;
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     * @return LinodeS3V3Adapter
     */
    protected function createAdapter(): FilesystemAdapter
    {
        $client = static::client($this->_getConfigArray());

        return new LinodeS3V3Adapter(
            $client,
            App::parseEnv($this->bucket),
            $this->_subfolder(),
            null,
            null,
            [],
            false,
        );
    }

    /**
     * Get the S3-compatible client.
     *
     * @param array $config client config
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
            $diff = (int)$expires->format('U') - (int)$now->format('U');
            $config['CacheControl'] = 'max-age=' . $diff;
        }

        if (!empty($this->contentDisposition)) {
            $config['ContentDisposition'] = $this->contentDisposition;
        }

        // Linode Object Storage doesn't support S3 object ACL headers like x-amz-acl.
        // Craft's base Flysystem implementation adds visibility metadata, which the
        // AWS S3 adapter translates into those ACL headers, so return our metadata
        // directly and leave object access control to the bucket's Linode settings.
        return $config;
    }

    /**
     * @inheritdoc
     */
    protected function invalidateCdnPath(string $path): bool
    {
        // Not implemented; Linode Object Storage doesn't provide a first-party CDN purge API here.
        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the parsed subfolder path.
     */
    private function _subfolder(): string
    {
        if ($this->subfolder && ($subfolder = rtrim(App::parseEnv($this->subfolder), '/')) !== '') {
            return $subfolder . '/';
        }

        return '';
    }

    /**
     * Get the config array for AWS clients.
     */
    private function _getConfigArray(): array
    {
        $credentials = $this->_getCredentials();

        return self::_buildConfigArray(
            $credentials['keyId'],
            $credentials['secret'],
            $credentials['region'],
            $credentials['endpoint'],
        );
    }

    /**
     * Build the config array.
     */
    private static function _buildConfigArray(?string $keyId = null, ?string $secret = null, ?string $region = null, ?string $endpoint = null): array
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
        $config['http_handler'] = class_exists('Aws\\Handler\\Guzzle\\GuzzleHandler')
            ? new \Aws\Handler\Guzzle\GuzzleHandler($client)
            : new \Aws\Handler\GuzzleV6\GuzzleHandler($client);

        return $config;
    }

    /**
     * Return the credentials as an array.
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
}
