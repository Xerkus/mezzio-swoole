<?php
/**
 * @see       https://github.com/zendframework/zend-expressive-swoole for the canonical source repository
 * @copyright Copyright (c) 2018 Zend Technologies USA Inc. (http://www.zend.com)
 * @license   https://github.com/zendframework/zend-expressive-swoole/blob/master/LICENSE.md New BSD License
 */

declare(strict_types=1);

namespace Zend\Expressive\Swoole;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Psr\Log\LoggerInterface;
use Swoole\Http\Request as SwooleHttpRequest;
use Swoole\Http\Response as SwooleHttpResponse;
use Swoole\Http\Server as SwooleHttpServer;
use Swoole\Process as SwooleProcess;
use Throwable;
use Zend\Expressive\Swoole\Exception;
use Zend\HttpHandlerRunner\Emitter\EmitterInterface;
use Zend\HttpHandlerRunner\RequestHandlerRunner;

use function date;
use function dechex;
use function file_exists;
use function file_get_contents;
use function filemtime;
use function filesize;
use function getcwd;
use function gmstrftime;
use function md5;
use function pathinfo;
use function sprintf;
use function time;
use function trim;
use function usleep;

/**
 * "Run" a request handler using Swoole.
 *
 * The RequestHandlerRunner will marshal a request using the composed factory, and
 * then pass the request to the composed handler. Finally, it emits the response
 * returned by the handler using the Swoole emitter.
 *
 * If the factory for generating the request raises an exception or throwable,
 * then the runner will use the composed error response generator to generate a
 * response, based on the exception or throwable raised.
 */
class RequestHandlerSwooleRunner extends RequestHandlerRunner
{
    /**
     * Default static file extensions supported
     *
     * @see https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Complete_list_of_MIME_types
     */
    public const DEFAULT_STATIC_EXTS = [
        '7z'    => 'application/x-7z-compressed',
        'aac'   => 'audio/aac',
        'arc'   => 'application/octet-stream',
        'avi'   => 'video/x-msvideo',
        'azw'   => 'application/vnd.amazon.ebook',
        'bin'   => 'application/octet-stream',
        'bmp'   => 'image/bmp',
        'bz'    => 'application/x-bzip',
        'bz2'   => 'application/x-bzip2',
        'css'   => 'text/css',
        'csv'   => 'text/csv',
        'doc'   => 'application/msword',
        'docx'  => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'eot'   => 'application/vnd.ms-fontobject',
        'epub'  => 'application/epub+zip',
        'es'    => 'application/ecmascript',
        'gif'   => 'image/gif',
        'htm'   => 'text/html',
        'html'  => 'text/html',
        'ico'   => 'image/x-icon',
        'jpg'   => 'image/jpg',
        'jpeg'  => 'image/jpg',
        'js'    => 'text/javascript',
        'json'  => 'application/json',
        'mp4'   => 'video/mp4',
        'mpeg'  => 'video/mpeg',
        'odp'   => 'application/vnd.oasis.opendocument.presentation',
        'ods'   => 'application/vnd.oasis.opendocument.spreadsheet',
        'odt'   => 'application/vnd.oasis.opendocument.text',
        'oga'   => 'audio/ogg',
        'ogv'   => 'video/ogg',
        'ogx'   => 'application/ogg',
        'otf'   => 'font/otf',
        'pdf'   => 'application/pdf',
        'png'   => 'image/png',
        'ppt'   => 'application/vnd.ms-powerpoint',
        'pptx'  => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'rar'   => 'application/x-rar-compressed',
        'rtf'   => 'application/rtf',
        'svg'   => 'image/svg+xml',
        'swf'   => 'application/x-shockwave-flash',
        'tar'   => 'application/x-tar',
        'tif'   => 'image/tiff',
        'tiff'  => 'image/tiff',
        'ts'    => 'application/typescript',
        'ttf'   => 'font/ttf',
        'txt'   => 'text/plain',
        'wav'   => 'audio/wav',
        'weba'  => 'audio/webm',
        'webm'  => 'video/webm',
        'webp'  => 'image/webp',
        'woff'  => 'font/woff',
        'woff2' => 'font/woff2',
        'xhtml' => 'application/xhtml+xml',
        'xls'   => 'application/vnd.ms-excel',
        'xlsx'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'xml'   => 'application/xml',
        'xul'   => 'application/vnd.mozilla.xul+xml',
        'zip'   => 'application/zip',
    ];

    /**
     * ETag validation type
     */
    const WEAK_VALIDATION = 'weak';
    const STRONG_VALIDATION = 'strong';

    /**
     * @var array
     */
    private $allowedStatic;

    /**
     * Cache the file extensions (type) for valid static file
     *
     * @var array
     */
    private $cacheTypeFile = [];

    /**
     * Keep CWD in daemon mode.
     *
     * @var string
     */
    private $cwd;

    /**
     * @var string
     */
    private $docRoot;

    /**
     * ETag validation type, 'weak' means Weak Validation, 'strong' means Strong Validation,
     * other value will not response ETag header.
     *
     * @var string
     */
    private $etagValidationType;

    /**
     * A request handler to run as the application.
     *
     * @var RequestHandlerInterface
     */
    private $handler;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * A manager to handle pid about the application.
     *
     * @var PidManager
     */
    private $pidManager;

    /**
     * Factory for creating an HTTP server instance.
     *
     * @var ServerFactory
     */
    private $serverFactory;

    /**
     * A factory capable of generating an error response in the scenario that
     * the $serverRequestFactory raises an exception during generation of the
     * request instance.
     *
     * The factory will receive the Throwable or Exception that caused the error,
     * and must return a Psr\Http\Message\ResponseInterface instance.
     *
     * @var callable
     */
    private $serverRequestErrorResponseGenerator;

    /**
     * A factory capable of generating a Psr\Http\Message\ServerRequestInterface instance.
     * The factory will not receive any arguments.
     *
     * @var callable
     */
    private $serverRequestFactory;

    /**
     * @throws Exception\InvalidConfigException if the configured or default
     *     document root does not exist.
     */
    public function __construct(
        RequestHandlerInterface $handler,
        callable $serverRequestFactory,
        callable $serverRequestErrorResponseGenerator,
        ServerFactory $serverFactory,
        array $config,
        LoggerInterface $logger = null,
        PidManager $pidManager
    ) {
        $this->handler = $handler;

        // Factories are cast as Closures to ensure return type safety.
        $this->serverRequestFactory = function ($request) use ($serverRequestFactory) : ServerRequestInterface {
            return $serverRequestFactory($request);
        };

        $this->serverRequestErrorResponseGenerator =
            function (Throwable $exception) use ($serverRequestErrorResponseGenerator) : ResponseInterface {
                return $serverRequestErrorResponseGenerator($exception);
            };

        $this->serverFactory = $serverFactory;

        $this->etagValidationType = $config['etag_type'] ?? self::WEAK_VALIDATION;
        $this->allowedStatic = $config['static_files'] ?? self::DEFAULT_STATIC_EXTS;
        $this->docRoot = $config['options']['document_root'] ?? getcwd() . '/public';
        if (! file_exists($this->docRoot)) {
            throw new Exception\InvalidConfigException(sprintf(
                'The document_root %s does not exist. Please check your configuration.',
                $this->docRoot
            ));
        }

        $this->logger = $logger ?: new StdoutLogger();
        $this->pidManager = $pidManager;
        $this->cwd = getcwd();
    }

    /**
     * Run the application
     */
    public function run() : void
    {
        $commandLine = new CommandLine();
        $options = $commandLine->parse();
        switch ($options->getAction()) {
            case CommandLineOptions::ACTION_HELP:
                $commandLine->emitHelpAndExit();
                break;
            case CommandLineOptions::ACTION_STOP:
                $this->stopServer();
                break;
            case CommandLineOptions::ACTION_START:
            default:
                $this->startServer([
                    'daemonize' => $options->daemonize(),
                    'worker_num' => $options->getNumberOfWorkers(),
                ]);
                break;
        }
    }

    /**
     * Start swoole server
     *
     * @param array $options Swoole server options
     */
    public function startServer(array $options = []) : void
    {
        $swooleServer = $this->serverFactory->createSwooleServer($options);
        $swooleServer->on('start', [$this, 'onStart']);
        $swooleServer->on('request', [$this, 'onRequest']);
        $swooleServer->start();
    }

    /**
     * Stop swoole server
     */
    public function stopServer() : void
    {
        if (! $this->isRunning()) {
            $this->logger->info('Server is not running yet');
            return;
        }
        [$masterPid, ] = $this->pidManager->read();
        $this->logger->info('Server stopping ...');
        $result = SwooleProcess::kill((int) $masterPid);
        $startTime = time();
        while (! $result) {
            if (! SwooleProcess::kill((int) $masterPid, 0)) {
                continue;
            }
            if (time() - $startTime >= 60) {
                $result = false;
                break;
            }
            usleep(10000);
        }
        if (! $result) {
            $this->logger->info('Server stop failure');
            return;
        }
        $this->pidManager->delete();
        $this->logger->info('Server stopped');
    }

    /**
     * Is swoole server running ?
     */
    public function isRunning() : bool
    {
        [$masterPid, $managerPid] = $this->pidManager->read();
        if ($managerPid) {
            // Swoole process mode
            return $masterPid && $managerPid && SwooleProcess::kill((int) $managerPid, 0);
        }
        // Swoole base mode, no manager process
        return $masterPid && SwooleProcess::kill((int) $masterPid, 0);
    }

    /**
     * On start event for swoole http server
     */
    public function onStart(SwooleHttpServer $server) : void
    {
        $this->pidManager->write($server->master_pid, $server->manager_pid);
        $this->logger->info('Swoole is running at {host}:{port}', [
            'host' => $server->host,
            'port' => $server->port,
        ]);
        // Reset CWD
        chdir($this->cwd);
    }

    /**
     * On HTTP request event for swoole http server
     */
    public function onRequest(
        SwooleHttpRequest $request,
        SwooleHttpResponse $response
    ) : void {
        $this->logger->info('{ts} - {remote_addr} - {request_method} {request_uri}', [
            'ts'             => date('Y-m-d H:i:sO', time()),
            'remote_addr'    => $request->server['remote_addr'],
            'request_method' => $request->server['request_method'],
            'request_uri'    => $request->server['request_uri']
        ]);

        if ($this->getStaticResource($request, $response)) {
            return;
        }

        $emitter = new SwooleEmitter($response);

        try {
            $psr7Request = ($this->serverRequestFactory)($request);
        } catch (Throwable $e) {
            // Error in generating the request
            $this->emitMarshalServerRequestException($emitter, $e);
            return;
        }

        $emitter->emit($this->handler->handle($psr7Request));
    }

    /**
     * Emit marshal server request exception
     */
    private function emitMarshalServerRequestException(
        EmitterInterface $emitter,
        Throwable $exception
    ) : void {
        $response = ($this->serverRequestErrorResponseGenerator)($exception);
        $emitter->emit($response);
    }

    /**
     * Get a static resource, if any, and set the swoole HTTP response.
     */
    private function getStaticResource(
        SwooleHttpRequest $request,
        SwooleHttpResponse $response
    ) : bool {
        $server = $request->server;
        if ($server['request_method'] !== 'GET' && $server['request_method'] !== 'HEAD') {
            return false;
        }
        $staticFile = $this->docRoot . $server['request_uri'];
        if (! isset($this->cacheTypeFile[$staticFile])
            && ! $this->cacheFile($staticFile)
        ) {
            return false;
        }

        $response->header('Content-Type', $this->cacheTypeFile[$staticFile], true);

        // Handle ETag and Last-Modified header
        $lastModifiedTime = filemtime($staticFile) ?? 0;
        $fileSize = filesize($staticFile) ?? 0;

        // Set Last-Modified header
        $lastModifiedTimeDateFormatted = trim(gmstrftime('%A %d-%b-%y %T %Z', $lastModifiedTime));
        $response->header('Last-Modified', $lastModifiedTimeDateFormatted, true);

        // Set ETag header
        $etag = '';
        switch ($this->etagValidationType) {
            case self::WEAK_VALIDATION:
                if ($lastModifiedTime && $fileSize) {
                    $etag = 'W/"' . dechex($lastModifiedTime) . '-' . dechex($fileSize) . '"';
                }
                break;
            case self::STRONG_VALIDATION:
                $etag = md5(file_get_contents($staticFile));
                break;
        }
        $etag && $response->header('ETag', $etag, true);

        // Handle ETag header of client
        $ifMatch = $request->header['if-match'] ?? '';
        $ifNoneMatch = $request->header['if-none-match'] ?? '';
        $clientEtag = $ifMatch ?: $ifNoneMatch;
        if ($clientEtag && $etag && $clientEtag === $etag) {
            $response->status(304);
            $response->end();
            return true;
        }

        $ifModifiedSince = $request->header['if-modified-since'] ?? '';
        if ($ifModifiedSince && $ifModifiedSince === $lastModifiedTimeDateFormatted) {
            $response->status(304);
            $response->end();
            return true;
        }

        $response->sendfile($staticFile);
        return true;
    }

    /**
     * Attempt to cache a static file resource.
     */
    private function cacheFile(string $fileName) : bool
    {
        $type = pathinfo($fileName, PATHINFO_EXTENSION);
        if (! isset($this->allowedStatic[$type])) {
            return false;
        }

        if (! file_exists($fileName)) {
            return false;
        }

        $this->cacheTypeFile[$fileName] = $this->allowedStatic[$type];
        return true;
    }
}
