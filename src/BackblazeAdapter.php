<?php
declare(strict_types=1);

namespace MarcAndreAppel\FlysystemBackblaze;

use BackblazeB2\Client;
use BackblazeB2\Exceptions\B2Exception;
use BackblazeB2\Exceptions\NotFoundException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use InvalidArgumentException;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\FilesystemException;
use League\Flysystem\InvalidVisibilityProvided;
use League\Flysystem\UnableToCheckExistence;
use League\Flysystem\UnableToCreateDirectory;
use League\Flysystem\UnableToDeleteDirectory;
use League\Flysystem\UnableToMoveFile;
use League\Flysystem\UnableToRetrieveMetadata;
use LogicException;

class BackblazeAdapter implements FilesystemAdapter
{
    use NotSupportingVisibilityTrait;

    public function __construct(
        protected Client $client,
        protected string $bucketName,
        protected mixed $bucketId = null
    ) {}

    public function fileExists($path): bool
    {
        return $this->getClient()
            ->fileExists([
                'FileName'   => $path,
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function write($path, $contents, Config $config): void
    {
        $file = $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
                'Body'       => $contents,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function writeStream($path, $resource, Config $config): void
    {
        $file = $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
                'Body'       => $resource,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function update($path, $contents, Config $config): array
    {
        $file = $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
                'Body'       => $contents,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function updateStream($path, $resource, Config $config)
    {
        $file = $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
                'Body'       => $resource,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    public function read($path): string
    {
        $file = $this->getClient()
            ->getFile([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
            ]);

        $fileContent = $this->getClient()
            ->download([
                'FileId' => $file->getId(),
            ]);

        /** @var string $fileContent */
        return $fileContent;
    }

    public function readStream($path)
    {
        $stream   = Psr7\Utils::streamFor();
        $download = $this->getClient()
            ->download([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $path,
                'SaveAs'     => $stream,
            ]);
        $stream->seek(0);

        try {
            $resource = Psr7\StreamWrapper::getResource($stream);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $download === true ? ['stream' => $resource] : false;
    }

    public function move($path, $newpath, Config $config): void
    {
        $this->copy($path, $newpath, $config);
        $this->delete($path);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function copy($path, $newPath, Config $config): void
    {
        $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $newPath,
                'Body'       => @file_get_contents($path),
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function delete($path): void
    {
        $this->getClient()
            ->deleteFile([
                'FileName'   => $path,
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    public function deleteDirectory($path): void
    {
        $this->getClient()
            ->deleteFile([
                'FileName'   => $path,
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function createDirectory($dirname, Config $config): void
    {
        $this->getClient()
            ->upload([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName'   => $dirname,
                'Body'       => '',
            ]);
    }

    public function getMetadata($path): bool
    {
        return false;
    }

    public function getMimetype($path): bool
    {
        return false;
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function getSize($path): array
    {
        $file = $this->getClient()
            ->getFile([
                'FileName'   => $path,
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    public function getTimestamp($path): array
    {
        $file = $this->getClient()
            ->getFile([
                'FileName'   => $path,
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);

        return $this->getFileInfo($file);
    }

    public function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function listContents($directory = '', $recursive = false): array
    {
        $fileObjects = $this->getClient()
            ->listFiles([
                'BucketId'   => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
        if ($recursive === true && $directory === '') {
            $regex = '/^.*$/';
        } elseif ($recursive === true && $directory !== '') {
            $regex = '/^'.preg_quote($directory).'\/.*$/';
        } elseif ($recursive === false && $directory === '') {
            $regex = '/^(?!.*\\/).*$/';
        } elseif ($recursive === false && $directory !== '') {
            $regex = '/^'.preg_quote($directory).'\/(?!.*\\/).*$/';
        } else {
            throw new InvalidArgumentException();
        }
        $fileObjects = array_filter($fileObjects, function ($fileObject) use ($regex) {
            return 1 === preg_match($regex, $fileObject->getName());
        });
        $normalized  = array_map(function ($fileObject) {
            return $this->getFileInfo($fileObject);
        }, $fileObjects);

        return array_values($normalized);
    }

    protected function getFileInfo($file): FileAttributes
    {
        return new FileAttributes(
            path: $file->getName(),
            fileSize: $file->getSize(),
            visibility: null,
            lastModified: $file->getUploadTimestamp(),
            mimeType: null,
            extraMetadata: []
        );
    }

    /**
     * @throws FilesystemException
     * @throws UnableToCheckExistence
     */
    public function directoryExists(string $path): bool
    {
       return $this->fileExists($path);
    }

    /**
     * Get the visibility of a file.
     *
     * @param string $path
     *
     * @throws LogicException
     */
    public function visibility($path): FileAttributes
    {
        throw new LogicException(get_class($this) . ' does not support visibility. Path: ' . $path);
    }

    /**
     * Set the visibility for a file.
     *
     * @param string $path
     * @param string $visibility
     *
     * @throws LogicException
     */
    public function setVisibility($path, $visibility): void
    {
        throw new LogicException(get_class($this) . ' does not support visibility. Path: ' . $path . ', visibility: ' . $visibility);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function mimeType(string $path): FileAttributes
    {
        throw new LogicException(get_class($this) . ' does not support mimeType. Path: ' . $path);
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function lastModified(string $path): FileAttributes
    {
        $timestamp = $this->getTimestamp($path)
    }

    /**
     * @throws UnableToRetrieveMetadata
     * @throws FilesystemException
     */
    public function fileSize(string $path): FileAttributes
    {
        // TODO: Implement fileSize() method.
    }

    /**
     * @throws UnableToMoveFile
     * @throws FilesystemException
     */
    public function move(string $source, string $destination, Config $config): void
    {
        // TODO: Implement move() method.
    }
}
