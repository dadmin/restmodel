<?php

namespace RestModel\Exceptions;

use Psr\Http\Message\ResponseInterface;

/**
 * Thrown by Controller::halt() to immediately stop action execution and return a response.
 * Replaces Slim 2's \Slim\Exception\Stop / $app->halt().
 */
class HttpStopException extends \RuntimeException
{
    private ResponseInterface $response;

    public function __construct(ResponseInterface $response)
    {
        $this->response = $response;
        parent::__construct('', $response->getStatusCode());
    }

    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}
