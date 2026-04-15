<?php

namespace RestModel\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use RestModel\Core\apiParams;
use RestModel\Exceptions\BadRequest400;
use RestModel\Exceptions\NotFound404;
use RestModel\Exceptions\InternalServerError500;
use RestModel\Exceptions\HttpStopException;
use League\Fractal\Manager;
use League\Fractal\Resource\Item;
use League\Fractal\Resource\Collection;


class APIController extends Controller {

    protected $model;
    protected $transformer;
    protected int $maxLimit = 1000;


    protected static function getControllerName(string $controllerName): string {
        return "API{$controllerName}Controller";
    }

    /**
     * Returns a Slim 4 compatible route handler closure with standard API response headers.
     *
     * Usage: $app->get('/items', APIController::init('Item', 'getList'));
     *        $app->get('/items/{id}', APIController::init('Item', 'getItem'));
     */
    public static function init(string $controllerName, string $actionName): \Closure {

        $controllerName = static::getControllerName($controllerName);

        return function(Request $request, Response $response, array $args) use ($controllerName, $actionName): Response {
            $response = $response
                ->withHeader('Accept', 'application/json')
                ->withHeader('Accept-Charset', 'utf-8')
                ->withHeader('Content-Type', 'application/json;charset=utf-8')
                ->withHeader('Access-Control-Allow-Origin', '*')
                ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
                ->withHeader('Access-Control-Allow-Headers', 'Origin, Content-Type, Accept')
                ->withHeader('X-MediaService-Time', date('c'))
                ->withHeader('X-MediaService-Version', '1.0');

            $controller = new $controllerName();
            $controller->request = $request;
            $controller->response = $response;

            if (!method_exists($controller, $actionName) && !is_callable([$controller, $actionName])) {
                throw new BadRequest400("Invalid URL");
            }

            try {
                call_user_func_array([$controller, $actionName], array_values($args));
            } catch (HttpStopException $e) {
                return $e->getResponse();
            }

            return $controller->response;
        };
    }

    public function response($data, $totalCount = false, $query = false, int $statusCode = 200): void {

        if ($this->transformer) {
            $fractal = new Manager();

            if ($totalCount === false) {
                $resource = new Item($data, $this->transformer);
            } else {
                $resource = new Collection($data, $this->transformer);
            }

            $responseData = $fractal->createData($resource)->toArray();

        } else {
            $responseData = ['data' => $data];
        }

        if ($totalCount !== false) {
            $responseData['totalCount'] = (int)$totalCount;
        }

        if ($query !== false) {
            $responseData['search'] = $query;
        }

        $json = json_encode($responseData, JSON_UNESCAPED_UNICODE);

        if ($json === false) {
            InternalServerError500::throwException("Response couldn't be encoded as JSON");
        }

        $this->response->getBody()->write($json);
        $this->response = $this->response->withStatus($statusCode);
    }

    public function getList(apiParams $params = null): void {

        $params = $params ?: apiParams::getParams($this->request, $this->maxLimit);
        $count = $this->model->getTotalCount($params);

        if ($params->getOffset() < 0) {
            BadRequest400::throwException("'offset' out of range");
        }

        $rows = $this->model->getMany($params);
        $this->response($rows, $count);
    }

    protected function decodeJSON(string $jsonString): array {

        if (!$jsonString) {
            BadRequest400::throwException('Body request is empty!');
        }

        $json = json_decode($jsonString, true, 20, JSON_OBJECT_AS_ARRAY);

        if (json_last_error()) {
            BadRequest400::throwException('Request JSON data is invalid!');
        }

        return $json;
    }

    public function getItem($id, int $statusCode = 200): void {

        $item = $this->model->getById($id);

        if (!$item) {
            NotFound404::throwException("Item with id = $id doesn't exist!");
        }

        $this->response($item, false, false, $statusCode);
    }

    public function addItem($body = false): mixed {

        $body = $body ?: $this->decodeJSON((string)$this->request->getBody());

        if (isset($body['id'])) {
            unset($body['id']);
        }

        return $this->model->setValues($body)->validateValues()->save();
    }

    public function isItemExist($id): array {

        $item = $this->model->getById($id);
        if (!$item) {
            NotFound404::throwException("Item with id = $id doesn't exist!");
        }

        return $item;
    }

    public function updateItem($id, $body = []): void {

        $body = $body ?: $this->decodeJSON((string)$this->request->getBody());
        $body['id'] = $id;

        $this->isItemExist($id);
        $this->model->setValues($body, true)->validateValues()->save();
    }

    public function deleteItem($id): void {

        $this->isItemExist($id);
        $this->model->delete($id);
        $this->halt(204);
    }

    public function markItemAsDeleted($id): void {

        $this->isItemExist($id);
        $this->model->markAsDeleted($id);
        $this->halt(204);
    }
}
