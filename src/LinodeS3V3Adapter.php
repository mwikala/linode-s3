<?php

declare(strict_types=1);

namespace mwikala\linodes3;

use Aws\ResultInterface;
use Aws\S3\S3ClientInterface;
use Generator;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Config;
use League\Flysystem\DirectoryAttributes;
use League\Flysystem\FileAttributes;
use League\Flysystem\PathPrefixer;
use League\Flysystem\StorageAttributes;
use League\Flysystem\UnableToCheckDirectoryExistence;
use League\Flysystem\UnableToCheckFileExistence;
use League\Flysystem\UnableToCopyFile;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToDeleteFile;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToRetrieveMetadata;
use League\Flysystem\UnableToSetVisibility;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\Visibility;
use League\MimeTypeDetection\FinfoMimeTypeDetector;
use League\MimeTypeDetection\MimeTypeDetector;
use Psr\Http\Message\StreamInterface;
use Throwable;

/**
 * Flysystem adapter for Linode Object Storage.
 *
 * Based on league/flysystem-aws-s3-v3's AwsS3V3Adapter (MIT, Frank de Jonge),
 * with ACL operations removed because Linode's S3-compatible API does not
 * implement object ACLs (x-amz-acl, GetObjectAcl, PutObjectAcl).
 *
 * @internal
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

    private const EXTRA_METADATA_FIELDS = [
        'Metadata',
        'StorageClass',
        'ETag',
        'VersionId',
    ];

    private S3ClientInterface $client;
    private string $bucket;
    private PathPrefixer $prefixer;
    private MimeTypeDetector $mimeTypeDetector;
    private array $options;
    private bool $streamReads;

    public function __construct(
        S3ClientInterface $client,
        string $bucket,
        string $prefix = '',
        ?MimeTypeDetector $mimeTypeDetector = null,
        array $options = [],
        bool $streamReads = true,
    ) {
        parent::__construct(
            $client,
            $bucket,
            $prefix,
            null,
            $mimeTypeDetector,
            $options,
            $streamReads,
            self::FORWARDED_OPTIONS,
            self::EXTRA_METADATA_FIELDS,
            self::MUP_AVAILABLE_OPTIONS,
        );

        $this->client = $client;
        $this->bucket = $bucket;
        $this->prefixer = new PathPrefixer($prefix);
        $this->mimeTypeDetector = $mimeTypeDetector ?? new FinfoMimeTypeDetector();
        $this->options = $options;
        $this->streamReads = $streamReads;
    }

    public function setVisibility(string $path, string $visibility): void
    {
        throw UnableToSetVisibility::atLocation($path, 'Linode Object Storage does not support object ACLs.');
    }

    public function visibility(string $path): FileAttributes
    {
        return new FileAttributes($path, null, Visibility::PRIVATE);
    }

    protected function determineAcl(Config $config): string
    {
        return '';
    }

    public function fileExists(string $path): bool
    {
        try {
            return $this->client->doesObjectExistV2($this->bucket, $this->prefixer->prefixPath($path), false, $this->options);
        } catch (Throwable $exception) {
            throw UnableToCheckFileExistence::forLocation($path, $exception);
        }
    }

    public function directoryExists(string $path): bool
    {
        try {
            $prefix = $this->prefixer->prefixDirectoryPath($path);
            $options = ['Bucket' => $this->bucket, 'Prefix' => $prefix, 'MaxKeys' => 1, 'Delimiter' => '/'];
            $command = $this->client->getCommand('ListObjectsV2', $options);
            $result = $this->client->execute($command);

            return $result->hasKey('Contents') || $result->hasKey('CommonPrefixes');
        } catch (Throwable $exception) {
            throw UnableToCheckDirectoryExistence::forLocation($path, $exception);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->upload($path, $contents, $config);
    }

    private function upload(string $path, $body, Config $config): void
    {
        $key = $this->prefixer->prefixPath($path);
        $options = $this->uploadOptionsFromConfig($config);
        $shouldDetermineMimetype = !array_key_exists('ContentType', $options['params']);

        if ($shouldDetermineMimetype && $mimeType = $this->mimeTypeDetector->detectMimeType($key, $body)) {
            $options['params']['ContentType'] = $mimeType;
        }

        try {
            // The 4th argument of S3Client::upload() is the ACL string; an empty
            // string makes the SDK apply no ACL, which Linode requires.
            $this->client->upload($this->bucket, $key, $body, '', $options);
        } catch (Throwable $exception) {
            throw UnableToWriteFile::atLocation($path, $exception->getMessage(), $exception);
        }
    }

    private function uploadOptionsFromConfig(Config $config): array
    {
        $config = $config->withDefaults($this->options);
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

        foreach (self::MUP_AVAILABLE_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');
            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    private function copyOptionsFromConfig(Config $config): array
    {
        $config = $config->withDefaults($this->options);
        $options = [];

        foreach (self::FORWARDED_OPTIONS as $option) {
            $value = $config->get($option, '__NOT_SET__');
            if ($value !== '__NOT_SET__') {
                $options[$option] = $value;
            }
        }

        return $options;
    }

    public function read(string $path): string
    {
        $body = $this->readObject($path, false);

        return (string)$body->getContents();
    }

    public function readStream(string $path)
    {
        /** @var resource $resource */
        $resource = $this->readObject($path, true)->detach();

        return $resource;
    }

    private function readObject(string $path, bool $wantsStream): StreamInterface
    {
        $options = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];

        if ($wantsStream && $this->streamReads && !isset($this->options['@http']['stream'])) {
            $options['@http']['stream'] = true;
        }

        $command = $this->client->getCommand('GetObject', $options + $this->options);

        try {
            return $this->client->execute($command)->get('Body');
        } catch (Throwable $exception) {
            throw UnableToReadFile::fromLocation($path, '', $exception);
        }
    }

    public function delete(string $path): void
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $command = $this->client->getCommand('DeleteObject', $arguments + $this->options);

        try {
            $this->client->execute($command);
        } catch (Throwable $exception) {
            throw UnableToDeleteFile::atLocation($path, '', $exception);
        }
    }

    public function deleteDirectory(string $path): void
    {
        $prefix = $this->prefixer->prefixPath($path);
        $prefix = ltrim(rtrim($prefix, '/'), '/') . '/';

        $objects = [];
        foreach ($this->retrievePaginatedListing(['Bucket' => $this->bucket, 'Prefix' => $prefix]) as $item) {
            if ($item instanceof StorageAttributes && $item->isFile()) {
                $objects[] = ['Key' => $this->prefixer->prefixPath($item->path())];
            }
        }

        if ($objects === []) {
            return;
        }

        try {
            $this->client->deleteObjects([
                'Bucket' => $this->bucket,
                'Delete' => ['Objects' => $objects, 'Quiet' => true],
            ]);
        } catch (Throwable $exception) {
            throw UnableToDeleteDirectory::atLocation($path, '', $exception);
        }
    }

    public function createDirectory(string $path, Config $config): void
    {
        try {
            $this->upload(rtrim($path, '/') . '/', '', $config);
        } catch (Throwable $exception) {
            throw UnableToCreateDirectory::dueToFailure($path, $exception);
        }
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        if ($source === $destination) {
            return;
        }

        $options = $this->copyOptionsFromConfig($config);
        $options['MetadataDirective'] = $config->get('MetadataDirective', 'COPY');

        try {
            // The 5th argument of S3Client::copy() is the ACL string; an empty
            // string makes the SDK apply no ACL, which Linode requires.
            $this->client->copy(
                $this->bucket,
                $this->prefixer->prefixPath($source),
                $this->bucket,
                $this->prefixer->prefixPath($destination),
                '',
                $options,
            );
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

    public function mimeType(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_MIME_TYPE);

        if ($attributes->mimeType() === null) {
            throw UnableToRetrieveMetadata::mimeType($path);
        }

        return $attributes;
    }

    public function lastModified(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_LAST_MODIFIED);

        if ($attributes->lastModified() === null) {
            throw UnableToRetrieveMetadata::lastModified($path);
        }

        return $attributes;
    }

    public function fileSize(string $path): FileAttributes
    {
        $attributes = $this->fetchFileMetadata($path, FileAttributes::ATTRIBUTE_FILE_SIZE);

        if ($attributes->fileSize() === null) {
            throw UnableToRetrieveMetadata::fileSize($path);
        }

        return $attributes;
    }

    public function listContents(string $path, bool $deep): iterable
    {
        $prefix = trim($this->prefixer->prefixPath($path), '/');
        $prefix = empty($prefix) ? '' : $prefix . '/';
        $options = ['Bucket' => $this->bucket, 'Prefix' => $prefix];

        if ($deep === false) {
            $options['Delimiter'] = '/';
        }

        $listing = $this->retrievePaginatedListing($options);

        foreach ($listing as $item) {
            yield $this->mapS3ObjectMetadata($item);
        }
    }

    private function retrievePaginatedListing(array $options): Generator
    {
        $resultPaginator = $this->client->getPaginator('ListObjectsV2', $options + $this->options);

        foreach ($resultPaginator as $result) {
            yield from ($result->get('CommonPrefixes') ?? []);
            yield from ($result->get('Contents') ?? []);
        }
    }

    private function mapS3ObjectMetadata(array $metadata): StorageAttributes
    {
        if (substr($metadata['Key'] ?? $metadata['Prefix'], -1) === '/') {
            return new DirectoryAttributes(
                $this->prefixer->stripDirectoryPrefix(rtrim($metadata['Key'] ?? $metadata['Prefix'], '/')),
            );
        }

        return new FileAttributes(
            $this->prefixer->stripPrefix($metadata['Key']),
            (int)($metadata['Size'] ?? 0),
            null,
            isset($metadata['LastModified'])
                ? strtotime((string)$metadata['LastModified'])
                : null,
            null,
            $this->extractExtraMetadata($metadata),
        );
    }

    private function extractExtraMetadata(array $metadata): array
    {
        $extracted = [];

        foreach (self::EXTRA_METADATA_FIELDS as $field) {
            if (isset($metadata[$field]) && $metadata[$field] !== '') {
                $extracted[$field] = $metadata[$field];
            }
        }

        return $extracted;
    }

    private function fetchFileMetadata(string $path, string $type): FileAttributes
    {
        $arguments = ['Bucket' => $this->bucket, 'Key' => $this->prefixer->prefixPath($path)];
        $command = $this->client->getCommand('HeadObject', $arguments + $this->options);

        try {
            /** @var ResultInterface $result */
            $result = $this->client->execute($command);
        } catch (Throwable $exception) {
            throw UnableToRetrieveMetadata::create($path, $type, '', $exception);
        }

        $attributes = $this->extractFileMetadataFromResult($result);

        return new FileAttributes(
            $path,
            $attributes[FileAttributes::ATTRIBUTE_FILE_SIZE] ?? null,
            $attributes[FileAttributes::ATTRIBUTE_VISIBILITY] ?? null,
            $attributes[FileAttributes::ATTRIBUTE_LAST_MODIFIED] ?? null,
            $attributes[FileAttributes::ATTRIBUTE_MIME_TYPE] ?? null,
            $attributes,
        );
    }

    private function extractFileMetadataFromResult(ResultInterface $result): array
    {
        $metadata = [
            FileAttributes::ATTRIBUTE_FILE_SIZE => isset($result['ContentLength']) ? (int)$result['ContentLength'] : null,
            FileAttributes::ATTRIBUTE_MIME_TYPE => $result['ContentType'] ?? null,
            FileAttributes::ATTRIBUTE_LAST_MODIFIED => isset($result['LastModified'])
                ? strtotime((string)$result['LastModified'])
                : null,
        ];

        foreach (self::EXTRA_METADATA_FIELDS as $field) {
            $value = $result[$field] ?? null;

            if ($value !== null && $value !== '') {
                $metadata[$field] = $value;
            }
        }

        return $metadata;
    }
}
