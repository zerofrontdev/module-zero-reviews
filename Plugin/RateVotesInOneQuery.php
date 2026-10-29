<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Reviews\Plugin;

use Magento\Framework\Data\CollectionFactory as DataCollectionFactory;
use Magento\Review\Model\ResourceModel\Rating\Option\Vote\CollectionFactory as VoteCollectionFactory;
use Magento\Review\Model\ResourceModel\Review\Collection as ReviewCollection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The ratings of a list of reviews in one query. Core's addRateVotes() loads
 * a vote collection per review: a product with twelve reviews cost twelve
 * queries on every page view. Each review still gets a collection of its
 * votes (rating code, percent, value), as the templates expect.
 */
class RateVotesInOneQuery
{
    public function __construct(
        private readonly VoteCollectionFactory $voteCollectionFactory,
        private readonly DataCollectionFactory $dataCollectionFactory,
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * @param ReviewCollection $subject
     * @param callable $proceed
     * @return ReviewCollection
     * @SuppressWarnings(PHPMD.UnusedFormalParameter)
     */
    public function aroundAddRateVotes(ReviewCollection $subject, callable $proceed): ReviewCollection
    {
        $reviews = $subject->getItems();
        if (!$reviews) {
            return $subject;
        }
        $storeId = (int) $this->storeManager->getStore()->getId();
        $votes = $this->voteCollectionFactory->create()
            ->setStoreFilter($storeId)
            ->addRatingInfo($storeId);
        $votes->getSelect()->where('main_table.review_id IN (?)', array_map('intval', array_keys($reviews)));
        $byReview = [];
        foreach ($votes as $vote) {
            $byReview[(int) $vote->getReviewId()][] = $vote;
        }
        foreach ($reviews as $id => $review) {
            $collection = $this->dataCollectionFactory->create();
            foreach ($byReview[(int) $id] ?? [] as $vote) {
                $collection->addItem($vote);
            }
            $review->setRatingVotes($collection);
        }

        return $subject;
    }
}
