<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Test\Unit\Model;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Zero\Reviews\Model\Photos;

/**
 * A review photo's WebP write is checked: a failed write leaves no file and
 * is an error the shopper sees, not a row pointing at nothing.
 */
class PhotosTest extends TestCase
{
    private string $base;
    /** @var string[] relative paths deleted through the media directory */
    private array $deleted = [];

    protected function setUp(): void
    {
        if (!function_exists('imagewebp')) {
            $this->markTestSkipped('GD without WebP');
        }
        $this->base = sys_get_temp_dir() . '/zero-review-photos-' . bin2hex(random_bytes(4));
        mkdir($this->base);
    }

    protected function tearDown(): void
    {
        if (isset($this->base) && is_dir($this->base)) {
            foreach (new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            ) as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($this->base);
        }
    }

    public function testAFailedWebpWriteThrowsAndLeavesNoFile(): void
    {
        // The folder isn't there and can't be made: imagewebp() answers false.
        $path = $this->base . '/zero_reviews/ab/missing/photo.webp';

        try {
            $this->write($this->media(), $path);
            $this->fail('a failed write was taken for a photo');
        } catch (LocalizedException $e) {
            $this->assertSame('the photo could not be saved.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
    }

    public function testAFolderThatCantBeMadeRemovesWhatWasThere(): void
    {
        // A half-written file from before, then the folder can't be made.
        mkdir($this->base . '/zero_reviews/ab', 0777, true);
        $path = $this->base . '/zero_reviews/ab/photo.webp';
        file_put_contents($path, 'RIFF');

        try {
            $this->write($this->media(true), $path);
            $this->fail('a failed write was taken for a photo');
        } catch (LocalizedException $e) {
            $this->assertSame('the photo could not be saved.', $e->getMessage());
        }

        $this->assertFileDoesNotExist($path);
        $this->assertSame(['zero_reviews/ab/photo.webp'], $this->deleted);
    }

    public function testAGoodWriteGivesTheScaledSize(): void
    {
        mkdir($this->base . '/zero_reviews/ab', 0777, true);
        $path = $this->base . '/zero_reviews/ab/photo.webp';

        $size = $this->write($this->media(), $path, imagecreatetruecolor(800, 400), 400);

        $this->assertSame([400, 200], $size);
        $this->assertSame(IMAGETYPE_WEBP, getimagesize($path)[2]);
        $this->assertSame([], $this->deleted);
    }

    /**
     * Photos::write() through reflection: store() needs a real PHP upload.
     *
     * @return array{0: int, 1: int}
     */
    private function write(WriteInterface $media, string $path, ?\GdImage $image = null, int $max = 1600): array
    {
        $photos = new Photos(
            $this->createStub(ResourceConnection::class),
            $this->createStub(Filesystem::class),
            $this->createStub(StoreManagerInterface::class)
        );
        $write = new \ReflectionMethod(Photos::class, 'write');

        return $write->invoke($photos, $image ?? imagecreatetruecolor(4, 4), $max, $path, $media);
    }

    /**
     * pub/media in a temp folder; create() makes nothing (or fails), as when
     * the disk refuses.
     */
    private function media(bool $createFails = false): WriteInterface
    {
        $media = $this->createStub(WriteInterface::class);
        $media->method('getRelativePath')->willReturnCallback(
            fn ($path = null) => ltrim(substr((string) $path, strlen($this->base)), '/')
        );
        $media->method('create')->willReturnCallback(function () use ($createFails) {
            if ($createFails) {
                throw new FileSystemException(__('Permission denied'));
            }
            return true;
        });
        $media->method('isFile')->willReturnCallback(fn ($path) => is_file($this->base . '/' . $path));
        $media->method('delete')->willReturnCallback(function ($path) {
            $this->deleted[] = $path;
            return unlink($this->base . '/' . $path);
        });

        return $media;
    }
}
