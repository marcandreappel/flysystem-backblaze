<?php
declare(strict_types=1);

namespace MarcAndreAppel\FlysystemBackblaze;

use BackblazeB2\Client;
use BackblazeB2\Exceptions\B2Exception;
use BackblazeB2\Exceptions\NotFoundException;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7;
use InvalidArgumentException;
use League\Flysystem\Adapter\AbstractAdapter;
use League\Flysystem\Adapter\Polyfill\NotSupportingVisibilityTrait;
use League\Flysystem\Config;

class BackblazeAdapter extends AbstractAdapter
{
    use NotSupportingVisibilityTrait;

    public function __construct(
        protected Client $client,
        protected string $bucketName,
        protected mixed $bucketId = null
    ) {}

    public function has($path)
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
    public function write($path, $contents, Config $config)
    {
        if ($this->isLargeFile(filesize($contents))) {
            $this->uploadLargeFile($path, $contents, $config);
        } else {
            $file = $this->getClient()->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $contents,
            ]);

            return $this->getFileInfo($file);
        }
    }

    /**
     * @param resource $contents
     *
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function writeStream($path, $contents, Config $config)
    {
        $streamMetaData = stream_get_meta_data($contents);

        if ($this->isLargeFile(filesize($streamMetaData['uri']))) {
            $this->uploadLargeFile($path, str_replace($path, '', $streamMetaData['uri']));
        } else {
            $file = $this->getClient()->upload([
                'BucketId' => $this->bucketId,
                'BucketName' => $this->bucketName,
                'FileName' => $path,
                'Body' => $contents,
            ]);

            return $this->getFileInfo($file);
        }
    }

    /**
     * @param string $fileName
     * @param string $path
     *
     * @return void
     */
    public function uploadLargeFile(string $fileName, string $path): void
    {
        $this->getClient()->uploadLargeFile([
            'BucketId' => $this->bucketId,
            'BucketName' => $this->bucketName,
            'FileName' => $fileName,
            'FilePath' => str_replace($fileName, '', $path),
        ]);
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function update($path, $contents, Config $config)
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
    public function read($path)
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

        return ['contents' => $fileContent];
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

    public function rename($path, $newpath)
    {
        return false;
    }

    /**
     * @throws GuzzleException
     * @throws B2Exception
     */
    public function copy($path, $newPath)
    {
        return $this->getClient()
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
    public function delete($path)
    {
        return $this->getClient()
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
    public function deleteDir($path)
    {
        return $this->getClient()
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
    public function createDir($dirname, Config $config)
    {
        return $this->getClient()
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

    protected function getFileInfo($file): array
    {
        return [
            'type'      => 'file',
            'path'      => $file->getName(),
            'timestamp' => substr((string) $file->getUploadTimestamp() ?? '', 0, -3),
            'size'      => $file->getSize(),
        ];
    }

    /**
     * Checks the size for the B2 5 Gb size limit
     * @param float $sizeInBytes
     * @return bool
     */
    protected function isLargeFile(float $sizeInBytes): bool
    {
        if ($sizeInBytes === 0.0) {
            return false;
        }
        for ($i = 0; $sizeInBytes > 1024; $i++) {
            $sizeInBytes /= 1024;
        }

        return $i >= 3 && $sizeInBytes >= 5;
    }
}
