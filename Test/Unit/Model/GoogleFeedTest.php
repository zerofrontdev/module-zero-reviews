<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Test\Unit\Model;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection as ProductCollection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory as ProductCollectionFactory;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Review\Model\ResourceModel\Review\Collection as ReviewCollection;
use Magento\Review\Model\ResourceModel\Review\CollectionFactory as ReviewCollectionFactory;
use Magento\Review\Model\Review;
use Magento\Review\Model\ReviewFactory;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Zero\Reviews\Model\GoogleFeed;
use Zero\Reviews\Model\Photos;

/**
 * Google's product reviews feed is built a page of reviews at a time, with
 * each page's products and photos, newest first.
 */
#[AllowMockObjectsWithoutExpectations]
class GoogleFeedTest extends TestCase
{
    /** @var array<int, array<int, array{0: string, 1: array}>> each review collection's calls */
    private array $reviewCalls = [];
    /** @var int[][] the product ids each product read asked for */
    private array $productReads = [];
    private Photos&MockObject $photos;
    private CacheInterface&MockObject $cache;

    protected function setUp(): void
    {
        $this->photos = $this->createMock(Photos::class);
        $this->cache = $this->createMock(CacheInterface::class);
        $this->cache->method('load')->willReturn(false);
    }

    public function testReviewsAreReadAPageAtATime(): void
    {
        // 1 200 reviews: 3 pages of 500; one review of product <page> on each page here.
        $pages = [1 => [$this->review(10, 1)], 2 => [$this->review(20, 2)], 3 => [$this->review(30, 3)]];
        $photoReads = [];
        $this->photos->method('forReviews')->willReturnCallback(function (array $ids) use (&$photoReads) {
            $photoReads[] = $ids;
            return [];
        });

        $xml = $this->feed($pages, 3)->get();

        $this->assertCount(3, $this->reviewCalls, 'one collection per page');
        foreach ($this->reviewCalls as $index => $calls) {
            $this->assertContains(['setPageSize', [500]], $calls);
            $this->assertContains(['setCurPage', [$index + 1]], $calls);
            $this->assertContains(['setDateOrder', []], $calls);
            $this->assertContains(['setOrder', ['main_table.review_id', 'DESC']], $calls);
            $this->assertContains(['clear', []], $calls, 'a page is let go before the next');
            $this->assertNotContains('getAllIds', array_column($calls, 0));
        }
        $this->assertSame([[1], [2], [3]], $this->productReads, 'products per page');
        $this->assertSame([[10], [20], [30]], $photoReads, 'photos per page');
        $feed = simplexml_load_string($xml);
        $this->assertNotFalse($feed);
        $this->assertSame(['10', '20', '30'], array_map('strval', $feed->xpath('/feed/reviews/review/review_id')));
        $this->assertSame('SKU-2', (string) $feed->reviews->review[1]->products->product->product_ids->skus->sku);
    }

    public function testNoReviewsIsAValidFeed(): void
    {
        $this->photos->method('forReviews')->willReturn([]);
        $this->cache->expects($this->once())->method('save')->with(
            $this->isString(),
            'zero_reviews_google_feed_1',
            [GoogleFeed::CACHE_TAG, Review::CACHE_TAG, Product::CACHE_TAG],
            3600
        );

        $xml = $this->feed([1 => []], 1)->get();

        $this->assertCount(1, $this->reviewCalls);
        $this->assertSame([], $this->productReads, 'no product read without reviews');
        $feed = simplexml_load_string($xml);
        $this->assertNotFalse($feed);
        $this->assertSame('2.3', (string) $feed->version);
        $this->assertSame('Demo Store', (string) $feed->publisher->name);
        $this->assertCount(0, $feed->reviews->children());
    }

    /**
     * @param array<int, Review[]> $pages reviews by page number
     */
    private function feed(array $pages, int $lastPage): GoogleFeed
    {
        $reviewFactory = $this->createStub(ReviewCollectionFactory::class);
        $reviewFactory->method('create')->willReturnCallback(function () use ($pages, $lastPage) {
            $index = count($this->reviewCalls);
            $this->reviewCalls[$index] = [];
            $page = 1;
            $collection = $this->createStub(ReviewCollection::class);
            foreach (['addStoreFilter', 'addStatusFilter', 'addFieldToFilter', 'setDateOrder', 'setOrder', 'setPageSize', 'setCurPage', 'addRateVotes', 'clear', 'getAllIds'] as $method) {
                $collection->method($method)->willReturnCallback(function (...$args) use ($collection, $method, $index, &$page) {
                    if ($method === 'setDateOrder') {
                        $args = [];
                    }
                    if ($method === 'setCurPage') {
                        $page = (int) $args[0];
                    }
                    $this->reviewCalls[$index][] = [$method, $args];
                    return $collection;
                });
            }
            $collection->method('getLastPageNumber')->willReturn($lastPage);
            $collection->method('getIterator')->willReturnCallback(function () use ($pages, &$page) {
                return new \ArrayIterator($pages[$page] ?? []);
            });
            return $collection;
        });

        $productFactory = $this->createStub(ProductCollectionFactory::class);
        $productFactory->method('create')->willReturnCallback(function () {
            $ids = [];
            $collection = $this->createStub(ProductCollection::class);
            foreach (['setStoreId', 'addStoreFilter', 'addAttributeToSelect', 'addUrlRewrite'] as $method) {
                $collection->method($method)->willReturnSelf();
            }
            $collection->method('addIdFilter')->willReturnCallback(function ($filter) use ($collection, &$ids) {
                $ids = array_values((array) $filter);
                $this->productReads[] = $ids;
                return $collection;
            });
            $collection->method('getIterator')->willReturnCallback(function () use (&$ids) {
                return new \ArrayIterator(array_map(fn (int $id) => $this->product($id), $ids));
            });
            return $collection;
        });

        $review = $this->createStub(Review::class);
        $review->method('getEntityIdByCode')->willReturn(1);
        $reviewModelFactory = $this->createStub(ReviewFactory::class);
        $reviewModelFactory->method('create')->willReturn($review);

        $store = $this->createStub(Store::class);
        $store->method('getId')->willReturn(1);
        $store->method('getFrontendName')->willReturn('Demo Store');
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new GoogleFeed(
            $reviewFactory,
            $productFactory,
            $this->createStub(Configurable::class),
            $reviewModelFactory,
            $this->photos,
            $storeManager,
            $this->createStub(ScopeConfigInterface::class),
            $this->cache
        );
    }

    private function review(int $id, int $productId): Review
    {
        $review = (new \ReflectionClass(Review::class))->newInstanceWithoutConstructor();
        $review->setId($id);
        $review->addData([
            'entity_pk_value' => $productId,
            'nickname' => 'Mara',
            'title' => 'Good',
            'detail' => 'Fits well.',
            'created_at' => '2026-10-30 10:00:00',
            'rating_votes' => [new DataObject(['percent' => 80])],
        ]);

        return $review;
    }

    private function product(int $id): Product
    {
        $product = $this->createPartialMock(Product::class, ['getProductUrl', 'getAttributeText', 'getSku']);
        $product->method('getSku')->willReturn('SKU-' . $id);
        $product->method('getProductUrl')->willReturn('https://shop.test/p' . $id . '.html');
        $product->method('getAttributeText')->willReturn('Acme');
        $product->setData(['entity_id' => $id, 'name' => 'Product ' . $id, 'type_id' => 'simple']);

        return $product;
    }
}
