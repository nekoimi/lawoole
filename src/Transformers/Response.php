<?php

namespace Lawoole\Transformers;

use Illuminate\Http\Request as IlluminateRequest;
use Illuminate\Http\Response as IlluminateResponse;
use Swoole\Http\Response as SwooleResponse;
use Swoole\Http\Request as SwooleRequest;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Response
{
    const CHUNK_SIZE = 8192;

    /**
     * @var SwooleResponse
     */
    protected $swooleResponse;

    /**
     * @var SwooleRequest
     */
    protected $swooleRequest;

    /**
     * @var IlluminateResponse
     */
    protected $illuminateResponse;

    /**
     * @param SwooleRequest $swooleRequest
     * @param SwooleResponse $swooleResponse
     * @param IlluminateResponse $illuminateResponse
     * @return Response
     */
    public static function make(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse, IlluminateResponse $illuminateResponse): Response
    {
        return new static($swooleRequest, $swooleResponse, $illuminateResponse);
    }

    /**
     * Response constructor.
     * @param SwooleRequest $swooleRequest
     * @param SwooleResponse $swooleResponse
     * @param IlluminateResponse $illuminateResponse
     */
    public function __construct(SwooleRequest $swooleRequest, SwooleResponse $swooleResponse, IlluminateResponse $illuminateResponse)
    {
        $this->setIlluminateResponse($illuminateResponse);
        $this->setSwooleResponse($swooleResponse);
        $this->setSwooleRequest($swooleRequest);
    }

    /**
     * Send HTTP headers and content.
     *
     * @throws \InvalidArgumentException
     */
    public function send()
    {
        $this->sendHeaders();
        $this->sendContent();
    }

    /**
     * Send HTTP headers.
     *
     * @throws \InvalidArgumentException
     */
    protected function sendHeaders()
    {
        $illuminateResponse = $this->getIlluminateResponse();

        /* RFC2616 - 14.18 says all Responses need to have a Date */
        if (! $illuminateResponse->headers->has('Date')) {
            $illuminateResponse->setDate(\DateTime::createFromFormat('U', time()));
        }

        // headers
        // allPreserveCaseWithoutCookies() doesn't exist before Laravel 5.3
        $headers = $illuminateResponse->headers->allPreserveCase();
        if (isset($headers['Set-Cookie'])) {
            unset($headers['Set-Cookie']);
        }
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $this->swooleResponse->header($name, $value);
            }
        }

        // status
        $this->swooleResponse->status($illuminateResponse->getStatusCode());

        // cookies
        // $cookie->isRaw() is supported after symfony/http-foundation 3.1
        // and Laravel 5.3, so we can add it back now
        foreach ($illuminateResponse->headers->getCookies() as $cookie) {
            $method = $cookie->isRaw() ? 'rawcookie' : 'cookie';
            $this->swooleResponse->$method(
                $cookie->getName(),
                $cookie->getValue(),
                $cookie->getExpiresTime(),
                $cookie->getPath(),
                $cookie->getDomain(),
                $cookie->isSecure(),
                $cookie->isHttpOnly()
            );
        }
    }

    /**
     * Send HTTP content.
     */
    protected function sendContent()
    {
        $illuminateResponse = $this->getIlluminateResponse();

        if ($illuminateResponse instanceof StreamedResponse && property_exists($illuminateResponse, 'output')) {
            // TODO Add Streamed Response with output
            $this->swooleResponse->end($illuminateResponse->output);
        } elseif ($illuminateResponse instanceof BinaryFileResponse) {
            $this->swooleResponse->sendfile($illuminateResponse->getFile()->getPathname());
        } else {
            $chunkGzip = $this->canGzipContent($illuminateResponse->headers->get('Content-Encoding'));
            $this->sendInChunk($illuminateResponse->getContent(), $chunkGzip);
        }
    }

    /**
     * Send content in chunk
     *
     * @param string $content
     * @param bool $chunkGzip
     */
    protected function sendInChunk(string $content, bool $chunkGzip)
    {
        if (strlen($content) <= static::CHUNK_SIZE) {
            $this->swooleResponse->end($content);
            return;
        }

        // Swoole Chunk mode does not support compress by default, this patch only supports gzip
        if ($chunkGzip) {
            $this->swooleResponse->header('Content-Encoding', 'gzip');
            $content = gzencode($content, config('swoole_http.server.options.http_compression_level', 3));
        }

        foreach (str_split($content, static::CHUNK_SIZE) as $chunk) {
            $this->swooleResponse->write($chunk);
        }

        $this->swooleResponse->end();
    }

    /**
     * @param SwooleResponse $swooleResponse
     * @return $this
     */
    protected function setSwooleResponse(SwooleResponse $swooleResponse): Response
    {
        $this->swooleResponse = $swooleResponse;

        return $this;
    }

    /**
     * @return SwooleResponse
     */
    public function getSwooleResponse(): SwooleResponse
    {
        return $this->swooleResponse;
    }

    /**
     * @param IlluminateResponse $illuminateResponse
     * @return $this
     */
    protected function setIlluminateResponse(IlluminateResponse $illuminateResponse): Response
    {
        if (! $illuminateResponse instanceof SymfonyResponse) {
            $content = (string) $illuminateResponse;
            $illuminateResponse = new IlluminateResponse($content);
        }

        $this->illuminateResponse = $illuminateResponse;

        return $this;
    }

    /**
     * @return IlluminateResponse
     */
    public function getIlluminateResponse(): IlluminateResponse
    {
        return $this->illuminateResponse;
    }

    /**
     * @param SwooleRequest $swooleRequest
     * @return $this
     */
    protected function setSwooleRequest(SwooleRequest $swooleRequest): Response
    {
        $this->swooleRequest = $swooleRequest;

        return $this;
    }

    /**
     * @return SwooleRequest
     */
    public function getSwooleRequest(): SwooleRequest
    {
        return $this->swooleRequest;
    }

    /**
     * @param string|null $responseContentEncoding
     * @return bool
     */
    protected function canGzipContent(string $responseContentEncoding = null): bool
    {
        return empty($responseContentEncoding) &&
            config('swoole_http.server.options.http_compression', true) &&
            !empty($this->swooleRequest->header['accept-encoding']) &&
            strpos($this->swooleRequest->header['accept-encoding'], 'gzip') !== false &&
            function_exists('gzencode');
    }
}
