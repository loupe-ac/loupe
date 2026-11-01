<?php

declare(strict_types=1);

namespace App\Api\Pagination;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * One page of a list: the items and the cursor of the next page, if there is one.
 *
 * @template T
 */
final readonly class Page
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        public array $items,
        public ?Cursor $next,
    ) {
    }

    /**
     * Cuts the rows a query fetched with PageRequest::fetchSize() down to one page.
     *
     * @template R
     *
     * @param list<R>             $rows     at most limit + 1 rows, in walk order
     * @param \Closure(R): Cursor $cursorOf the cursor that resumes after a given row
     *
     * @return self<R>
     */
    public static function fromRows(array $rows, PageRequest $request, \Closure $cursorOf): self
    {
        if (\count($rows) <= $request->limit) {
            return new self($rows, null);
        }

        $items = \array_slice($rows, 0, $request->limit);

        return new self($items, $cursorOf($items[$request->limit - 1]));
    }

    /**
     * @param \Closure(T): mixed $present turns an item into its wire form
     */
    public function toResponse(Request $request, \Closure $present): JsonResponse
    {
        $next = null;
        if (null !== $this->next) {
            $query = $request->query->all();
            $query['cursor'] = $this->next->encode();
            $next = $request->getSchemeAndHttpHost().$request->getBaseUrl().$request->getPathInfo().'?'.http_build_query($query);
        }

        $response = new JsonResponse(['items' => array_map($present, $this->items), 'next' => $next]);
        if (null !== $next) {
            $response->headers->set('Link', sprintf('<%s>; rel="next"', $next));
        }

        return $response;
    }
}
