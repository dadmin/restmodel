<?php

namespace RestModel\Core;

use Psr\Http\Message\ServerRequestInterface as Request;
use RestModel\Exceptions\BadRequest400;

class apiParams {

    protected int $offset = 0;
    protected int $limit = 0;
    protected array $filter = [];
    protected array $order = [];
    protected ?string $query = null;

    /**
     * Build an apiParams instance from a PSR-7 request.
     *
     * @param Request $request  PSR-7 server request
     * @param int     $maxLimit Maximum allowed value for the 'limit' parameter
     */
    static public function getParams(Request $request, int $maxLimit = 1000): self {

        $queryParams = $request->getQueryParams();

        $limit = (int)($queryParams['limit'] ?? 0);

        if ($limit <= 0) {
            $limit = $maxLimit;
        } elseif ($limit > $maxLimit) {
            BadRequest400::throwException("Value of parameter 'limit' > " . $maxLimit);
        }

        $offset = (int)($queryParams['offset'] ?? 0);

        if ($offset < 0) {
            BadRequest400::throwException("'offset' should be positive or 0");
        }

        $self = new self();
        $self->setOffset($offset)->setLimit($limit)
            ->setFilter(self::parseJSONParams($request, 'filter'))
            ->setOrder(self::parseJSONParams($request, 'order'))
            ->setQuery($queryParams['q'] ?? null);

        if (!empty($queryParams['r'])) {
            $self->setQuery($queryParams['r']);
        }

        return $self;
    }

    static public function parseJSONParams(Request $request, string $name): array {

        $queryParams = $request->getQueryParams();
        $params = $queryParams[$name] ?? null;

        if (!$params) {
            return [];
        }

        $params = json_decode($params, true);

        if (json_last_error()) {
            BadRequest400::throwException("'$name' JSON data is invalid!");
        }

        if (!is_array($params)) {
            BadRequest400::throwException("'$name' JSON should be object");
        }

        return $params;
    }

    public function setOffset(int $value): self {
        $this->offset = $value;
        return $this;
    }

    public function getOffset(): int {
        return $this->offset;
    }

    public function getLimit(): int {
        return $this->limit;
    }

    public function setLimit(int $value): self {
        $this->limit = $value;
        return $this;
    }

    public function getFilter(): array {
        return $this->filter;
    }

    public function setFilter(array $value): self {
        $this->filter[] = $value;
        return $this;
    }

    public function getOrder(): array {
        return $this->order;
    }

    public function setOrder(array $value): self {
        $this->order = $value;
        return $this;
    }

    public function getQuery(): ?string {
        return $this->query;
    }

    public function setQuery(?string $value): self {
        $this->query = $value;
        return $this;
    }

    public function __invoke(): array {
        return [
            'offset' => $this->offset,
            'limit'  => $this->limit,
            'filter' => $this->filter,
            'sort'   => $this->order,
        ];
    }
}
