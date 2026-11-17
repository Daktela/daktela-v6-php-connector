<?php

declare(strict_types=1);

namespace Daktela\DaktelaV6\Http\Internal;

use Daktela\DaktelaV6\Exception\RequestException;
use Daktela\DaktelaV6\Response\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Turns an HTTP response of the API into a Response.
 * @internal
 */
final class ResponseParser
{
    public function __construct(private readonly Redactor $redactor, private readonly ?LoggerInterface $logger)
    {
    }

    /**
     * Parse HTTP response into Response object.
     *
     * @param ResponseInterface $httpResponse HTTP response
     * @return Response Parsed response
     * @throws RequestException If JSON parsing fails
     */
    public function parse(ResponseInterface $httpResponse): Response
    {
        $rawBody = (string)$httpResponse->getBody();
        $status = $httpResponse->getStatusCode();
        // PHP records the current values of the parameters in the trace of the exception created below, so the
        // response, whose body may echo the token, is moved out of them
        $httpResponse = null;
        $responseBody = $rawBody;
        if ($responseBody !== '') {
            try {
                $responseBody = json_decode($responseBody, false, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException $ex) {
                $message = 'json_decode error: ' . $ex->getMessage();
                $this->logger?->error('Failed to parse API response', [
                    'error' => $message,
                ]);
                // The exception is not chained: its trace holds the raw response body as an argument
                throw new RequestException($message, 0, null, $status, $this->redactor->responseBody($rawBody));
            }
        }

        $errors = is_object($responseBody) ? $this->redactor->redactValues(self::normalizeErrors($responseBody->error ?? null)) : [];

        if (!isset($responseBody->result)) {
            $this->logger?->debug('API response received (no result)', [
                'status' => $status,
                'has_errors' => !empty($errors),
            ]);
            return new Response(null, 0, $errors, $status);
        }

        $result = $responseBody->result;
        // A list is wrapped as {data, total}; its data may be null on an error page. Any other result, e.g. a
        // single record, is returned as it is, also when the record has a `data` field of its own that is null.
        $isList = is_object($result) && property_exists($result, 'data')
            && ($result->data !== null || property_exists($result, 'total'));
        $responseData = $isList ? $result->data : $result;
        $total = (int)(is_object($result) ? ($result->total ?? 1) : 1);

        $this->logger?->debug('API response received', [
            'status' => $status,
            'total' => $total,
            'has_errors' => !empty($errors),
        ]);

        return new Response($responseData, $total, $errors, $status);
    }

    /**
     * Normalizes the `error` member of an API response into an array.
     * @param mixed $errors decoded `error` member
     * @return array list or map of errors
     */
    public static function normalizeErrors(mixed $errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }
        if (is_array($errors)) {
            return $errors;
        }
        if (is_object($errors)) {
            // Only the top level is converted so that list entries keep their decoded shape
            return get_object_vars($errors);
        }

        return [$errors];
    }

    /**
     * Returns the first error message reported by the API, searching nested lists and maps.
     * @param array $errors errors in the shape returned by normalizeErrors()
     * @return string|null first non-empty message, or null when there is none
     */
    public static function firstErrorMessage(array $errors): ?string
    {
        foreach ($errors as $error) {
            if (is_object($error)) {
                $error = get_object_vars($error);
            }
            $message = is_array($error) ? self::firstErrorMessage($error) : (is_string($error) ? trim($error) : null);
            if ($message !== null && $message !== '') {
                return $message;
            }
        }

        return null;
    }
}
