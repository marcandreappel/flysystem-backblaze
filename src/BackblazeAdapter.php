<?php
declare(strict_types=1);

namespace MarcAndreAppel\FlysystemBackblaze;

use BackblazeB2\Client;
use BackblazeB2\Exceptions\B2Exception;
use BackblazeB2\Exceptions\NotFoundException;
use BackblazeB2\File;
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
    public function __construct(
        protected Client $client,
        protected string $bucketName,
        protected mixed  $bucketId = null
    )
    {
    }

    public function fileExists(string $path): bool
    {
        return $this->getClient()
            ->fileExists([
                'FileName' => $path,
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function write(string $path, string $contents, Config $config): void
    {
        $file = $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $contents,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function writeStream(string $path, mixed $resource, Config $config): void
    {
        $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $resource,
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function update(string $path, string $contents, Config $config): array
    {
        $file = $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $contents,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function updateStream(string $path, mixed $resource, Config $config): array
    {
        $file = $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $resource,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    public function read(string $path): string
    {
        $file = $this->getFile($path);

        $fileContent = $this->getClient()
            ->download([
                'FileId' => $file->getId(),
            ]);

        /** @var string $fileContent */
        return $fileContent;
    }

    public function readStream(string $path): mixed
    {
        $stream = Psr7\Utils::streamFor();
        $download = $this->getClient()
            ->download([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'SaveAs' => $stream,
            ]);
        $stream->seek(0);

        try {
            $resource = Psr7\StreamWrapper::getResource($stream);
        } catch (InvalidArgumentException) {
            return false;
        }

        return $download === true ? ['stream' => $resource] : false;
    }

    /**
     * @throws B2Exception
     * @throws NotFoundException
     * @throws GuzzleException
     */
    public function move(string $path, string $newPath, Config $config): void
    {
        $this->copy($path, $newPath, $config);
        $this->delete($path);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function copy(string $path, string $newPath, Config $config): void
    {
        $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $newPath,
                'Body' => @file_get_contents($path),
            ]);
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function delete(string $path): void
    {
        $this->getClient()
            ->deleteFile([
                'FileName' => $path,
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @param string $path
     * @return void
     * @throws B2Exception
     * @throws GuzzleException
     * @throws NotFoundException
     * @obsolete
     */
    public function deleteDir(string $path): void
    {
        $this->deleteDirectory($path);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    public function deleteDirectory(string $path): void
    {
        $this->getClient()
            ->deleteFile([
                'FileName' => $path,
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * @param string $directoryName
     * @param Config $config
     * @return void
     * @throws B2Exception
     * @throws GuzzleException
     * @obsolete
     */
    public function createDir(string $directoryName, Config $config): void
    {
        $this->createDirectory($directoryName, $config);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function createDirectory(string $directoryName, Config $config): void
    {
        $this->getClient()
            ->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $directoryName,
                'Body' => '',
            ]);
    }

    /**
     * @param string $path
     * @return FileAttributes
     *
     * @throws B2Exception
     * @throws GuzzleException
     * @throws NotFoundException
     * @deprecated by $this->mimeType($path), $this->fileSize($path), ...
     */
    public function getMetadata(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    /**
     * @param string $path
     * @return bool
     *
     * @throws B2Exception
     * @throws GuzzleException
     * @throws NotFoundException
     * @deprecated by $this->mimeType($path)
     */
    public function getMimetype(string $path): string
    {
        return $this->mimeType($path)->mimeType();
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function getSize(string $path): array
    {
        return $this->getFileInfo($path);
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
                'FileName' => $path,
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);

        return $this->getFileInfo($file);
    }

    /**
     * Limited information by B2 package.
     *
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function listContents(string $directory = '', bool $recursive = false): array
    {
        $fileObjects = $this->getClient()
            ->listFiles([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);

        if ($recursive === true && $directory === '') {
            $regex = '/^.*$/';
        } elseif ($recursive === true && $directory !== '') {
            $regex = '/^' . preg_quote($directory) . '\/.*$/';
        } elseif ($recursive === false && $directory === '') {
            $regex = '/^(?!.*\\/).*$/';
        } elseif ($recursive === false && $directory !== '') {
            $regex = '/^' . preg_quote($directory) . '\/(?!.*\\/).*$/';
        } else {
            throw new InvalidArgumentException();
        }
        $fileObjects = array_filter($fileObjects, function ($fileObject) use ($regex) {
            return 1 === preg_match($regex, $fileObject->getName());
        });

        $normalized = array_map(function ($fileObject) {
            return $this->attributes($fileObject);
        }, $fileObjects);

        return array_values($normalized);
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
    public function visibility(string $path): FileAttributes
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
    public function setVisibility(string $path, string $visibility): void
    {
        throw new LogicException(get_class($this) . ' does not support visibility. Path: ' . $path . ', visibility: ' . $visibility);
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function mimeType(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    /**
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function lastModified(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    /**
     * @param string $path
     * @return FileAttributes
     * @throws B2Exception
     * @throws GuzzleException
     * @throws NotFoundException
     */
    public function fileSize(string $path): FileAttributes
    {
        return $this->attributes($path);
    }

    protected function getClient(): Client
    {
        return $this->client;
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    protected function getFile(string $path): File
    {
        return $this->getClient()
            ->getFile([
                'FileName' => $path,
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
            ]);
    }

    /**
     * Returns an array with all information about the file.
     *
     * @param string|File $file If not an instance of File, try to load an instance from B2.
     * @throws GuzzleException
     * @throws NotFoundException
     * @throws B2Exception
     */
    public function getFileInfo(string|File $file): array
    {
        if (!$file instanceof File) {
            $file = $this->getFile($file);
        }

        return [
            'type' => 'file',
            'path' => $file->getName(),
            'timestamp' => substr($file->getUploadTimestamp() ?? '', 0, -3),
            'size' => $file->getSize(),
        ];
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     * @throws NotFoundException
     */
    protected function attributes(string|File $file): FileAttributes
    {
        if (!$file instanceof File) {
            $file = $this->getFile($file);
        }

        return new FileAttributes(
            path: $file->getName(),
            fileSize: $file->getSize(),
            visibility: null,
            lastModified: $file->getUploadTimestamp(),
            mimeType: $file->getType(),
            extraMetadata: []
        );
    }
}
