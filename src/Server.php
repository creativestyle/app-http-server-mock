<?php

namespace Creativestyle\AppHttpServerMock;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Process\Process;
use Composer\Autoload\ClassLoader;

abstract class Server
{
    const SERVER_NAME = 'AppHttpServerMock';

    /**
     * @var array
     */
    private $requestHandlers = [];

    /**
     * @var Process
     */
    private $serverProcess;

    /**
     * @var string
     */
    private $frontControllerPath;

    /**
     * @var int
     */
    private $port;

    /**
     * @var array
     */
    private $supportedMethods;

    /**
     * @param int|null $port Will be chosen randomly if not specified
     * @param array|null $supportedMethods Array of supported methods, HEAD/GET are always supported, rest
     *                                     is added automatically based on what registered handlers can process
     */
    public function __construct(int $port = null, array $supportedMethods = null)
    {
        if (null === $port) {
            $port = rand(63200, 63500);
        }

        $this->port = $port;
        $this->supportedMethods = array_unique(array_merge(['GET', 'HEAD', $supportedMethods]));

        $this->registerRequestHandlers();
    }

    public function getBaseUrl(): string
    {
        return sprintf('http://127.0.0.1:%s', $this->port);
    }

    abstract protected function registerRequestHandlers();

    /**
     * @param string|string[] $method HTTP Method or a list
     * @param string $urlpattern
     * @param callable $callback
     */
    protected function registerRequestHandler($method, string $urlpattern, callable $callback)
    {
        if ($this->isRunning()) {
            throw new \RuntimeException('Handlers cannot be registered while server is already running');
        }

        if (!is_array($method)) {
            $method = [$method];
        }

        $urlpattern = trim($urlpattern);
        $method = array_map('strtoupper', $method);

        $this->supportedMethods = array_unique(array_merge($this->supportedMethods, $method));

        foreach ($method as $methodName) {
            $this->requestHandlers[] = [$methodName, $urlpattern, $callback];
        }
    }

    /**
     * @param Response|string|array|null $value
     * @return Response|null
     */
    protected function transformHandlerReturnValueToResponse($value): ?Response
    {
        if ($value instanceof Response) {
            return $value;
        }

        if (is_string($value)) {
            return new Response($value, 200, ['Content-Type' => 'text/plain']);
        }

        if (is_array($value)) {
            return new Response(json_encode($value, JSON_PRETTY_PRINT), 200, ['Content-Type' => 'application/json']);
        }

        if (null === $value) {
            return new Response('', 204);
        }

        return null;
    }

    protected function processResponse(Response $response): Response
    {
        $response->headers->add(['X-Server' => static::SERVER_NAME]);

        return $response;
    }

    private function matchHandler(string $requestMethod, string $requestPath): array
    {
        foreach ($this->requestHandlers as $handlerData) {
            list($method, $pattern, $callback) = $handlerData;

            if ($requestMethod !== $method) {
                continue;
            }

            if ($requestPath === $pattern) {
                return [$callback, $pattern, []];
            }

            if (preg_match('|^' . str_replace('|', '\|', $pattern) . '$|i', $requestPath, $matches)) {
                return [$callback, $pattern, array_slice($matches, 1)];
            }
        }

        return [null, null, []];
    }

    private function createErrorResponse(int $code, string $body)
    {
        return new Response($body, $code, [
            'Content-Type' => 'text/plain'
        ]);
    }

    private function getResponse(Request $request): Response
    {
        $method = $request->getMethod();
        $requestPath = $request->getPathInfo();

        if (!in_array($method, $this->supportedMethods)) {
            return $this->createErrorResponse(405, sprintf('This server does not support a %s request', $method));
        }

        list($handler, $matchedPattern, $matchedGroups) = $this->matchHandler($method, $requestPath);

        if ($method === 'HEAD' && null === $handler) {
            list($handler, $matchedPattern, $matchedGroups) = $this->matchHandler('GET', $requestPath);
        }

        if (null === $handler) {
            return $this->createErrorResponse(404, sprintf('Path "%s" was not found on this server', $requestPath));
        }

        $response = $this->transformHandlerReturnValueToResponse($handler($request, $matchedGroups));

        if ($method === 'HEAD') {
            $response->setContent('');
        }

        if (null === $response) {
            throw new \RuntimeException(sprintf('Request handler for "%s %s" returned a value that cannot be transformed into response', $method, $matchedPattern));
        }

        return $response;
    }

    private function handleRequest(Request $request): Response
    {
        try {
            $response = $this->getResponse($request);
        } catch (\Exception $exception) {
            return $this->createErrorResponse(500, sprintf('Internal server error - exception %s: %s',
                get_class($exception),
                $exception->getMessage()
            ));
        }

        return $this->processResponse($response);
    }

    private function createFrontController(): string
    {
        $path = sprintf('%s/app-server-mock-%s.php', sys_get_temp_dir(), spl_object_hash($this));

        $autoloadPath = $this->getCurrentAutoloadPath();
        $fqcn = static::class;

        file_put_contents($path, "<?php require '${autoloadPath}'; \\${fqcn}::runFrontController();");

        return $this->frontControllerPath = $path;
    }

    private function createStartServerCommand(int $port): array
    {
        return [
            PHP_BINARY,
            '-S', sprintf('127.0.0.1:%s', $port),
            $this->createFrontController()
        ];
    }

    public function start()
    {
        $this->stop();

        $this->serverProcess = new Process(
            $this->createStartServerCommand($this->port)
        );

        $this->serverProcess->start();

        // Give it 500ms to start
        usleep(50000);
    }

    public function isRunning(): bool
    {
        return $this->serverProcess && $this->serverProcess->isRunning();
    }

    public static function runFrontController(): Server
    {
        if (php_sapi_name() === 'cli-server') {
            ini_set('display_errors', 1);
            ini_set('display_startup_errors', 1);
            error_reporting(E_ALL);

            $request = Request::createFromGlobals();
            $app = new static();

            $response = $app->handleRequest($request);
            $response->send();

            return $app;
        }

        throw new \RuntimeException('This function should be used only in cli-server, not your tests.');
    }

    private function getCurrentAutoloadPath(): string
    {
        $loader = $this->getComposerAutoloader();

        $paths = [
            $loader ? dirname($loader->findFile(Process::class)) . '/../../autoload.php' : '',
            __DIR__ . '/../../../autoload.php',
            getcwd() . '/vendor/autoload.php'
        ];

        foreach ($paths as $path) {
            if (is_file($path)) {
                return realpath($path);
            }
        }

        throw new \RuntimeException('Could not find autoload.php');
    }

    private function getComposerAutoloader(): ClassLoader
    {
        foreach (spl_autoload_functions() as $callback) {
            if (!is_array($callback)) {
                continue;
            }

            if (is_a($callback[0], ClassLoader::class)) {
                return $callback[0];
            }
        }

        throw new \RuntimeException('Could not find composer autoloader');
    }

    /**
     * Stop the server if running and cleanup if any garbage is left.
     */
    public function stop()
    {
        if ($this->frontControllerPath && is_file($this->frontControllerPath)) {
            unlink($this->frontControllerPath);
            $this->frontControllerPath = null;
        }

        if ($this->isRunning()) {
            $this->serverProcess->stop(0, 9);
            $this->serverProcess = null;
        }
    }

    public function __destruct()
    {
        $this->stop();
    }
}

