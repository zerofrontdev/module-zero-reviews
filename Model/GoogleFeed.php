<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory as ReviewCollectionFactory;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Approved reviews as Google's product reviews feed (2.3), for star ratings.
 * Products are identified as in Zero_ProductFeed (brand, SKUs as mpns);
 * reviews without a rating are left out. Rebuilt hourly at most and on change.
 */
class GoogleFeed
{
    /** Cleaned when a review is saved or deleted (Observer\CleanFeed): saving one only cleans its product's own tag. */
    public const CACHE_TAG = 'zero_reviews_google_feed';

    private const LIFETIME = 3600;
    /** Reviews read at a time: a large store's feed never holds them all in memory. */
    private const PAGE = 500;
    private const CACHE_TAGS = [self::CACHE_TAG, Review::CACHE_TAG, Product::CACHE_TAG];

    public function __construct(
        private readonly ReviewCollectionFactory $reviewCollectionFactory,
        private readonly ProductCollectionFactory $productCollectionFactory,
        private readonly Configurable $configurable,
        private readonly ReviewFactory $reviewFactory,
        private readonly Photos $photos,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly CacheInterface $cache
    ) {
    }

    public function get(): string
    {
        /** @var Store $store */
        $store = $this->storeManager->getStore();
        $key = 'zero_reviews_google_feed_' . $store->getId();
        $xml = $this->cache->load($key);
        if ($xml === false) {
            $xml = $this->render($store);
            $this->cache->save($xml, $key, self::CACHE_TAGS, self::LIFETIME);
        }

        return (string) $xml;
    }

    private function render(Store $store): string
    {
        $storeName = $this->storeName($store);
        $entityId = (int) $this->reviewFactory->create()->getEntityIdByCode(Review::ENTITY_PRODUCT_CODE);

        $xml = new \XMLWriter();
        $xml->openMemory();
        $xml->setIndent(true);
        $xml->startDocument('1.0', 'UTF-8');
        $xml->startElement('feed');
        $xml->writeAttribute('xmlns:vc', 'http://www.w3.org/2007/XMLSchema-versioning');
        $xml->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
        $xml->writeAttribute(
            'xsi:noNamespaceSchemaLocation',
            'http://www.google.com/shopping/reviews/schema/product/2.3/product_reviews.xsd'
        );
        $xml->writeElement('version', '2.3');
        $xml->startElement('publisher');
        $xml->writeElement('name', $storeName);
        $xml->endElement();
        $xml->startElement('reviews');
        $page = 1;
        do {
            // Newest first; the id breaks ties so no review moves between pages.
            $reviews = $this->reviewCollectionFactory->create()
                ->addStoreFilter((int) $store->getId())
                ->addStatusFilter(Review::STATUS_APPROVED)
                ->addFieldToFilter('main_table.entity_id', $entityId)
                ->setDateOrder()
                ->setOrder('main_table.review_id', 'DESC')
                ->setPageSize(self::PAGE)
                ->setCurPage($page);
            $lastPage = (int) $reviews->getLastPageNumber();
            $reviews->addRateVotes(); // one vote query for the page (Plugin\RateVotesInOneQuery)
            $productIds = [];
            $reviewIds = [];
            foreach ($reviews as $review) {
                $productIds[] = (int) $review->getEntityPkValue();
                $reviewIds[] = (int) $review->getId();
            }
            $products = $this->products($store, array_values(array_unique($productIds)));
            $photos = $this->photos->forReviews($reviewIds);
            foreach ($reviews as $review) {
                $product = $products[(int) $review->getEntityPkValue()] ?? null;
                $rating = $this->rating($review);
                if ($product === null || $rating === null) {
                    continue;
                }
                $this->review($xml, $review, $rating, $product, $photos[(int) $review->getId()] ?? [], $storeName);
            }
            $reviews->clear();
        } while (++$page <= $lastPage);
        $xml->endElement();
        $xml->endElement();
        $xml->endDocument();

        return $xml->outputMemory();
    }

    /**
     * @param array{name: string, url: string, sku: string, mpns: string[], brand: string} $product
     * @param array<int, array{url: string}> $photos
     */
    private function review(
        \XMLWriter $xml,
        Review $review,
        float $rating,
        array $product,
        array $photos,
        string $storeName
    ): void {
        $xml->startElement('review');
        $xml->writeElement('review_id', (string) $review->getId());
        $xml->startElement('reviewer');
        $xml->writeElement('name', trim((string) $review->getNickname()) ?: 'Anonymous');
        $xml->endElement();
        $xml->writeElement('review_timestamp', gmdate('Y-m-d\TH:i:s\Z', (int) strtotime((string) $review->getCreatedAt() . ' UTC')));
        if (trim((string) $review->getTitle()) !== '') {
            $xml->writeElement('title', trim((string) $review->getTitle()));
        }
        $xml->writeElement('content', trim((string) $review->getDetail()));
        if ($photos) {
            $xml->startElement('reviewer_images');
            foreach ($photos as $photo) {
                $xml->startElement('reviewer_image');
                $xml->writeElement('url', $photo['url']);
                $xml->endElement();
            }
            $xml->endElement();
        }
        $xml->startElement('review_url');
        $xml->writeAttribute('type', 'group');
        $xml->text($product['url']);
        $xml->endElement();
        $xml->startElement('ratings');
        $xml->startElement('overall');
        $xml->writeAttribute('min', '1');
        $xml->writeAttribute('max', '5');
        $xml->text(rtrim(rtrim(number_format($rating, 1, '.', ''), '0'), '.'));
        $xml->endElement();
        $xml->endElement();
        $xml->startElement('products');
        $xml->startElement('product');
        $xml->startElement('product_ids');
        $xml->startElement('mpns');
        foreach ($product['mpns'] as $mpn) {
            $xml->writeElement('mpn', $mpn);
        }
        $xml->endElement();
        $xml->startElement('skus');
        $xml->writeElement('sku', $product['sku']);
        $xml->endElement();
        $xml->startElement('brands');
        $xml->writeElement('brand', $product['brand'] ?: $storeName);
        $xml->endElement();
        $xml->endElement();
        $xml->writeElement('product_name', $product['name']);
        $xml->writeElement('product_url', $product['url']);
        $xml->endElement();
        $xml->endElement();
        $xml->writeElement('is_spam', 'false');
        $xml->endElement();
    }

    /**
     * The review's votes, averaged, on Google's 1–5 scale; null without any.
     */
    private function rating(Review $review): ?float
    {
        $votes = $review->getRatingVotes();
        if (!$votes || !count($votes)) {
            return null;
        }
        $sum = 0.0;
        foreach ($votes as $vote) {
            $sum += (float) $vote->getPercent();
        }

        return max(1.0, min(5.0, round($sum / count($votes) / 20, 1)));
    }

    /**
     * @param int[] $ids
     * @return array<int, array{name: string, url: string, sku: string, mpns: string[], brand: string}>
     */
    private function products(Store $store, array $ids): array
    {
        if (!$ids) {
            return [];
        }
        $collection = $this->productCollectionFactory->create()
            ->setStoreId((int) $store->getId())
            ->addStoreFilter($store)
            ->addIdFilter($ids)
            ->addAttributeToSelect(['name', 'manufacturer', 'url_key'])
            ->addUrlRewrite();
        $vendor = trim((string) $this->scopeConfig->getValue(
            'zero_product_feed/general/vendor',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        ));
        $products = [];
        foreach ($collection as $product) {
            $mpns = [(string) $product->getSku()];
            if ($product->getTypeId() === Configurable::TYPE_CODE) {
                $mpns = $this->childSkus((int) $product->getId()) ?: $mpns;
            }
            $products[(int) $product->getId()] = [
                'name' => (string) $product->getName(),
                'url' => (string) $product->getProductUrl(),
                'sku' => (string) $product->getSku(),
                'mpns' => $mpns,
                'brand' => trim((string) $product->getAttributeText('manufacturer')) ?: $vendor,
            ];
        }

        return $products;
    }

    /**
     * @return string[]
     */
    private function childSkus(int $parentId): array
    {
        $ids = array_merge(...array_values($this->configurable->getChildrenIds($parentId)) ?: [[]]);
        if (!$ids) {
            return [];
        }

        return array_values(array_map(
            'strval',
            $this->productCollectionFactory->create()->addIdFilter($ids)->getColumnValues('sku')
        ));
    }

    private function storeName(Store $store): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'general/store_information/name',
            ScopeInterface::SCOPE_STORE,
            $store->getId()
        )) ?: (string) $store->getFrontendName();
    }
}
