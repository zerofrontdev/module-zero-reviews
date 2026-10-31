<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Model;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * A review's photos (zero_review_photo, files under media/zero_reviews/).
 *
 * An upload is decoded and written anew (GD, WebP): what's stored is only
 * pixels, without the camera's EXIF (GPS position included) and without
 * anything else the file carried. A photo is 1600 px on its long side at
 * most, with a 400 px thumbnail; a phone's EXIF orientation is applied first.
 */
class Photos
{
    public const MAX_PHOTOS = 5;
    public const MAX_BYTES = 8 * 1024 * 1024;
    private const MAX_PIXELS = 40_000_000;
    private const SIZE = 1600;
    private const THUMB = 400;
    private const DIR = 'zero_reviews';
    private const TABLE = 'zero_review_photo';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Filesystem $filesystem,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * The largest photo the server takes: this module's limit, or PHP's
     * upload_max_filesize when lower.
     */
    public function maxBytes(): int
    {
        return min(self::MAX_BYTES, $this->iniBytes('upload_max_filesize') ?: self::MAX_BYTES);
    }

    /**
     * All of a review's photos together, as PHP's post_max_size allows.
     */
    public function requestLimitMegabytes(): int
    {
        return (int) floor(($this->iniBytes('post_max_size') ?: self::MAX_BYTES) / 1024 / 1024);
    }

    /**
     * "8M" → 8388608; 0 when unset or unlimited.
     */
    private function iniBytes(string $key): int
    {
        $value = trim((string) ini_get($key));
        $number = (int) $value;
        $unit = strtolower(substr($value, -1));

        return $number * match ($unit) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };
    }

    /**
     * Stores the uploads (PHP upload arrays) that are photos.
     *
     * @param array<int, array{name?: string, tmp_name?: string, error?: int, size?: int}> $uploads
     * @return string[] why the ones that aren't were left out
     */
    public function add(int $reviewId, array $uploads): array
    {
        $errors = [];
        $position = 0;
        foreach ($uploads as $upload) {
            if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $name = (string) ($upload['name'] ?? '');
            if ($position >= self::MAX_PHOTOS) {
                $errors[] = (string) __('%1: only %2 photos per review.', $name, self::MAX_PHOTOS);
                continue;
            }
            try {
                $this->store($reviewId, $upload, $position);
                $position++;
            } catch (LocalizedException $e) {
                $errors[] = $name . ': ' . $e->getMessage();
            }
        }

        return $errors;
    }

    /**
     * @param int[] $reviewIds
     * @return array<int, array<int, array{url: string, thumb: string, width: int, height: int}>> by review id
     */
    public function forReviews(array $reviewIds): array
    {
        if (!$reviewIds) {
            return [];
        }
        $db = $this->resource->getConnection();
        $rows = $db->fetchAll($db->select()
            ->from($this->resource->getTableName(self::TABLE))
            ->where('review_id IN (?)', array_map('intval', $reviewIds))
            ->order(['review_id', 'position']));
        $media = rtrim((string) $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA), '/');
        $photos = [];
        foreach ($rows as $row) {
            $photos[(int) $row['review_id']][] = [
                'url' => $media . '/' . self::DIR . '/' . $row['file'],
                'thumb' => $media . '/' . self::DIR . '/thumb/' . $row['file'],
                'width' => (int) $row['width'],
                'height' => (int) $row['height'],
            ];
        }

        return $photos;
    }

    /**
     * The review's files (its rows go with it: foreign key).
     */
    public function deleteFiles(int $reviewId): void
    {
        $db = $this->resource->getConnection();
        $files = $db->fetchCol($db->select()
            ->from($this->resource->getTableName(self::TABLE), ['file'])
            ->where('review_id = ?', $reviewId));
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        foreach ($files as $file) {
            foreach ([self::DIR . '/' . $file, self::DIR . '/thumb/' . $file] as $path) {
                if ($media->isFile($path)) {
                    $media->delete($path);
                }
            }
        }
    }

    /**
     * @param array{tmp_name?: string, error?: int, size?: int} $upload
     * @throws LocalizedException
     */
    private function store(int $reviewId, array $upload, int $position): void
    {
        $tmp = (string) ($upload['tmp_name'] ?? '');
        if (in_array($upload['error'] ?? 0, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)) {
            throw new LocalizedException(__('larger than %1 MB.', round($this->maxBytes() / 1024 / 1024, 1)));
        }
        if (($upload['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file($tmp)) {
            throw new LocalizedException(__('the upload failed.'));
        }
        if ((int) ($upload['size'] ?? 0) > $this->maxBytes() || filesize($tmp) > $this->maxBytes()) {
            throw new LocalizedException(__('larger than %1 MB.', round($this->maxBytes() / 1024 / 1024, 1)));
        }
        $image = $this->decode($tmp);
        $file = bin2hex(random_bytes(1)) . '/' . bin2hex(random_bytes(12)) . '.webp';
        $media = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        try {
            [$width, $height] = $this->write($image, self::SIZE, $media->getAbsolutePath(self::DIR . '/' . $file), $media);
            try {
                $this->write($image, self::THUMB, $media->getAbsolutePath(self::DIR . '/thumb/' . $file), $media);
            } catch (LocalizedException $e) {
                // No photo without its thumbnail: the main file goes too.
                $this->remove($media, self::DIR . '/' . $file);
                throw $e;
            }
        } finally {
            imagedestroy($image);
        }
        $this->resource->getConnection()->insert($this->resource->getTableName(self::TABLE), [
            'review_id' => $reviewId,
            'file' => $file,
            'width' => $width,
            'height' => $height,
            'position' => $position,
        ]);
    }

    /**
     * @throws LocalizedException
     */
    private function decode(string $path): \GdImage
    {
        $info = $this->quietly(static fn () => getimagesize($path));
        if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new LocalizedException(__('not a JPEG, PNG or WebP image.'));
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new LocalizedException(__('too many pixels.'));
        }
        $image = $this->quietly(static fn () => imagecreatefromstring((string) file_get_contents($path)));
        if (!$image instanceof \GdImage) {
            throw new LocalizedException(__('the image could not be read.'));
        }
        if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
            $orientation = (int) ($this->quietly(static fn () => exif_read_data($path))['Orientation'] ?? 1);
            $angle = [3 => 180, 6 => -90, 8 => 90][$orientation] ?? 0;
            if ($angle !== 0) {
                $rotated = imagerotate($image, $angle, 0);
                if ($rotated instanceof \GdImage) {
                    imagedestroy($image);
                    $image = $rotated;
                }
            }
        }

        return $image;
    }

    /**
     * GD and EXIF readers warn on a broken file; here that is an answer
     * (false), not an error.
     */
    private function quietly(callable $read): mixed
    {
        set_error_handler(static fn () => true);
        try {
            return $read();
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @return array{0: int, 1: int} the size written
     */
    private function write(\GdImage $image, int $max, string $path, Filesystem\Directory\WriteInterface $media): array
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        $out = imagecreatetruecolor($w, $h);
        imagealphablending($out, false);
        imagesavealpha($out, true);
        imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, $width, $height);
        try {
            $media->create($media->getRelativePath(dirname($path)));
            $written = $this->quietly(static fn () => imagewebp($out, $path, 82));
        } catch (\Throwable) {
            $written = false;
        } finally {
            imagedestroy($out);
        }
        if ($written !== true) {
            // A full disk or a missing folder: no half-written file stays behind.
            $this->remove($media, $media->getRelativePath($path));
            throw new LocalizedException(__('the photo could not be saved.'));
        }

        return [$w, $h];
    }

    private function remove(Filesystem\Directory\WriteInterface $media, string $path): void
    {
        try {
            if ($media->isFile($path)) {
                $media->delete($path);
            }
        } catch (\Throwable) {
            // Nothing to remove, or it can't be: the upload fails either way.
            return;
        }
    }
}
