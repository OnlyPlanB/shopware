<?php declare(strict_types=1);

namespace Shopware\Api\Listing\Event\ListingSorting;

use Shopware\Api\Listing\Struct\ListingSortingSearchResult;
use Shopware\Context\Struct\TranslationContext;
use Shopware\Framework\Event\NestedEvent;

class ListingSortingSearchResultLoadedEvent extends NestedEvent
{
    const NAME = 'listing_sorting.search.result.loaded';

    /**
     * @var ListingSortingSearchResult
     */
    protected $result;

    public function __construct(ListingSortingSearchResult $result)
    {
        $this->result = $result;
    }

    public function getName(): string
    {
        return self::NAME;
    }

    public function getContext(): TranslationContext
    {
        return $this->result->getContext();
    }
}