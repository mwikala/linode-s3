<?php

declare(strict_types=1);

namespace mwikala\linodes3;

use Aws\S3\S3ClientInterface;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Throwable;

/**
 * Flysystem S3 adapter variant for Linode Object Storage.
 *
 * Linode's S3-compatible API does not implement object ACL operations, so this
 * adapter avoids x-amz-acl headers and GetObjectAcl/PutObjectAcl calls.
 */
class LinodeS3V3Adapter extends AwsS3V3Adapter
{
    private const FORWARDED_OPTIONS = [
        'CacheControl',
        'ContentDisposition',
        'ContentEncoding',
        'ContentLength',
        'ContentType',
        'Expires',
        'Metadata',
        'MetadataDirective',
        'RequestPayer',
        'SSECustomerAlgorithm',
        'SSECustomerKey',
        'SSECustomerKeyMD5',
        'SSEKMSKeyId',
        'ServerSideEncryption',
        'StorageClass',
        'Tagging',
        'WebsiteRedirectLocation',
        'ChecksumAlgorithm',
        'CopySourceSSECustomerAlgorithm',
        'CopySourceSSECustomerKey',
        'CopySourceSSECustomerKeyMD5',
    ];

    public function __construct(
        private S3ClientInterface $linodeClient,
        private string $linodeBucket,
        private string $linodePrefix = '',
        ?\League\Flysystem\AwsS3V3\VisibilityConverter $visibility = null,
        ?MimeTypeDetector $mimeTypeDetector = null,
        private array $linodeOptions = [],
        bool $streamReads = true,
    ) {
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();

        parent::__construct(
            $linodeClient,
            $linodeBucket,
            $linodePrefix,
            $visibility,
            $this->mimeTypeDetector,
            $linodeOptions,
            $streamReads,
        );
    }

    private MimeTypeDetector $mimeTypeDetector;

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->upload(rtrim($path, '/') . '/', '', $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }

        $arguments = array_merge($this->copyOptionsFromConfig($config), [
            'Bucket' => $this->linodeBucket,
            'Key' => $this->prefixPath($destination),
            'CopySource' => sprintf('%s/%s', $this->linodeBucket, $this->prefixPath($source)),
            'MetadataDirective' => $config->get('MetadataDirective', 'COPY'),
        ]);

        try {
            $this->linodeClient->copyObject($arguments);
        } catch (Throwable $exception) {
            throw UnableToCopyFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function move(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }

        try {
            $this->copy($source, $destination, $config);
            $this->delete($source);
        } catch (Throwable $exception) {
            throw UnableToMoveFile::fromLocationTo($source, $destination, $exception);
        }
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Linode Object Storage does not support object ACLs.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    private function upload(string $path, $body, Config $config): void
    {
        $key = $this->prefixPath($path);
        $options = $this->multipartOptionsFromConfig($config);

        if (!isset($options['params']['ContentType']) && ($mimeType = $this->mimeTypeDetector->detectMimeType($key, $body))) {
            $options['params']['ContentType'] = $mimeType;
        }

        try {
            $this->linodeClient->upload($this->linodeBucket, $key, $body, null, $options);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    private function multipartOptionsFromConfig(Config $config): array
    {
        $config = $config->withDefaults($this->linodeOptions);
        $options = ['params' => []];

        if ($mimetype = $config->get('mimetype')) {
            $options['params']['ContentType'] = $mimetype;
        }

        foreach (self::FORWARDED_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');
            if ($value !== '__NOT_SET__') {
                $options['params'][$option] = $value;
            }
        }

        foreach (AwsS3V3Adapter::MUP_AVAILABLE_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');
            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    private function copyOptionsFromConfig(Config $config): array
    {
        $config = $config->withDefaults($this->linodeOptions);
        $options = [];

        foreach (self::FORWARDED_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');
            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    private function prefixPath(string $path): string
    {
        return trim($this->linodePrefix . ltrim($path, '/'), '/');
    }
}
