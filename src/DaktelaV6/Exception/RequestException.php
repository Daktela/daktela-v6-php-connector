<?php

namespace Daktela\DaktelaV6\Exception;

use Exception;
use Throwable;

class RequestException extends Exception
{
    /** @var int|null HTTP status code of the failed response, if a response was received */
    private ?int $httpStatus;
    /** @var string|null raw body of the failed response, if a response was received */
    private ?string $responseBody;
    /** @var array errors reported by the API in the failed response */
    private array $apiErrors;

    /**
     * @param string $message exception message
     * @param int $code exception code (the HTTP status for HTTP errors)
     * @param Throwable|null $previous previous exception
     * @param int|null $httpStatus HTTP status code of the failed response
     * @param string|null $responseBody raw body of the failed response
     * @param array $apiErrors errors reported by the API in the failed response
     */
    public function __construct(
        $message,
        $code = 0,
        ?Throwable $previous = null,
        ?int $httpStatus = null,
        ?string $responseBody = null,
        array $apiErrors = []
    ) {
        parent::__construct($message, $code, $previous);
        $this->httpStatus = $httpStatus;
        $this->responseBody = $responseBody;
        $this->apiErrors = $apiErrors;
    }

    /**
     * @return int|null HTTP status code of the failed response, or null when no response was received
     */
    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /**
     * @return string|null raw body of the failed response, or null when no response was received
     */
    public function getResponseBody(): ?string
    {
        return $this->responseBody;
    }

    /**
     * @return array errors reported by the API in the failed response
     */
    public function getApiErrors(): array
    {
        return $this->apiErrors;
    }
}
