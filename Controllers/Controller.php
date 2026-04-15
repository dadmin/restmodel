<?php

namespace RestModel\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Whoops\Run;
use Whoops\Handler\PrettyPageHandler;
use RestModel\Exceptions\BadRequest400;
use RestModel\Exceptions\InternalServerError500;
use RestModel\Exceptions\APIException;
use RestModel\Exceptions\HttpStopException;
use PDO;
use PDOStatement;

class Controller {

    protected Request $request;
    protected Response $response;

    protected static function getControllerName(string $controllerName): string {
        return "{$controllerName}Controller";
    }

    /**
     * Returns a Slim 4 compatible route handler closure.
     * URL path parameters are passed as positional arguments to $actionName.
     *
     * Usage: $app->get('/items', Controller::init('Item', 'getList'));
     *        $app->get('/items/{id}', Controller::init('Item', 'getItem'));
     */
    public static function init(string $controllerName, string $actionName): \Closure {

        $controllerName = static::getControllerName($controllerName);

        return function(Request $request, Response $response, array $args) use ($controllerName, $actionName): Response {
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

    /**
     * Immediately stops action execution and returns the given HTTP status.
     * Equivalent to Slim 2's $app->halt().
     */
    protected function halt(int $status, string $body = ''): never {
        $this->response = $this->response->withStatus($status);
        if ($body !== '') {
            $this->response->getBody()->write($body);
        }
        throw new HttpStopException($this->response);
    }

    public function __($value): void {
        error_log((string)$value);
    }

    /**
     * Slim 4 404 handler — wire up via error middleware in the application.
     */
    public static function notFound(Request $request, Response $response): Response {
        $queryParams = $request->getQueryParams();
        error_log('Not found url: ' . $request->getUri()->getPath() . ($queryParams ? ' GET params: ' . print_r($queryParams, 1) : ''));
        return $response->withStatus(404);
    }

    /**
     * Error handler helper — wrap in a Slim 4 error middleware callable in the application:
     *
     *   $middleware->setDefaultErrorHandler(
     *       function(Request $req, \Throwable $e, bool $d, bool $l, bool $ld) use ($app) {
     *           return Controller::error($e, $req, $app->getResponseFactory()->createResponse());
     *       }
     *   );
     */
    public static function error(\Throwable $e, Request $request, Response $response): Response {

        if ($e instanceof APIException) {
            error_log("API error [{$e->getHTTPCode()}][{$e->getCode()}]: " . print_r(['error' => $e->getErrors()], 1));
            $response->getBody()->write(json_encode(['error' => $e->getErrors()], JSON_FORCE_OBJECT));
            return $response
                ->withStatus($e->getHTTPCode())
                ->withHeader('Content-Type', 'application/json');
        }

        error_log("API error [500][{$e->getCode()}]: {$e->getMessage()}\n{$e->getTraceAsString()}");

        return $response->withStatus(500);
    }

    /**
     * Returns a Whoops Run instance for development error display.
     * Register as a Slim 4 error handler or middleware in the application.
     */
    public static function createWhoopsHandler(): Run {
        $whoops = new Run();
        $whoops->pushHandler(new PrettyPageHandler());
        return $whoops;
    }

    public function sendCSVFile($csv, string $outputName = 'file.csv', array $columnNames = []): void {

        $this->response = $this->response
            ->withHeader('Content-type', 'application/csv')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $outputName . '"; modification-date="' . date('r') . '";');

        if ($csv instanceof PDOStatement) {

            if (!($output = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'r+'))) {
                InternalServerError500::throwException("Can't open output stream");
            }

            $flag = false;
            while ($row = $csv->fetch(PDO::FETCH_ASSOC)) {
                if (!$flag) {
                    $columnNames = $columnNames ?: array_keys($row);
                    fputcsv($output, $columnNames);
                    $flag = true;
                }
                fputcsv($output, $row);
            }

            rewind($output);
            $body = stream_get_contents($output);
            fclose($output);

        } elseif (is_array($csv)) {

            if (!($output = fopen('php://temp/maxmemory:' . (5 * 1024 * 1024), 'r+'))) {
                InternalServerError500::throwException("Can't open output stream");
            }

            if ($columnNames) {
                fputcsv($output, $columnNames);
            }

            foreach ($csv as $row) {
                fputcsv($output, $row);
            }

            rewind($output);
            $body = stream_get_contents($output);
            fclose($output);

        } else {
            $body = $csv;
            $this->response = $this->response
                ->withHeader('Content-Length', (string)strlen($csv))
                ->withHeader('Cache-Control', 'no-cache, must-revalidate')
                ->withHeader('Pragma', 'no-cache')
                ->withHeader('Expires', '0');
        }

        $this->response->getBody()->write($body);
        $this->halt(200);
    }
}
