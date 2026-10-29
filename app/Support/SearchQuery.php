<?php

namespace App\Support;

use Closure;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Facades\Log;
use Meilisearch\Exceptions\ApiException;
use Meilisearch\Exceptions\CommunicationException;
use Meilisearch\Exceptions\InvalidResponseBodyException;
use Meilisearch\Exceptions\JsonDecodingException;
use Meilisearch\Exceptions\TimeOutException;
use Psr\Http\Client\ClientExceptionInterface;

final class SearchQuery
{
    /** @var array<string, true> */
    private array $reported = [];

    /**
     * @template T
     *
     * @param  Closure(): T  $query
     * @return T
     *
     * @throws SearchUnavailable
     */
    public function run(Closure $query): mixed
    {
        try {
            return $query();
        } catch (ApiException|CommunicationException|InvalidResponseBodyException|JsonDecodingException|TimeOutException|ClientExceptionInterface $exception) {
            $status = $exception instanceof ApiException || $exception instanceof InvalidResponseBodyException
                ? (int) $exception->httpStatus : null;
            $code = $exception instanceof ApiException && in_array($exception->errorCode, [
                'index_not_found', 'invalid_api_key', 'missing_authorization_header', 'invalid_search_filter',
                'invalid_search_sort', 'invalid_search_attributes_to_search_on', 'internal', 'unavailable',
                'too_many_search_requests', 'invalid_index_uid',
            ], true) ? $exception->errorCode : null;
            $type = $exception instanceof ApiException && in_array($exception->errorType, [
                'invalid_request', 'internal', 'auth', 'system',
            ], true) ? $exception->errorType : null;
            $category = match (true) {
                $exception instanceof TimeOutException,
                $exception instanceof CommunicationException && $exception->getPrevious() instanceof ConnectException
                    && ($exception->getPrevious()->getHandlerContext()['errno'] ?? null) === 28 => 'timeout',
                $exception instanceof CommunicationException => 'communication',
                $exception instanceof ClientExceptionInterface => 'transport',
                $status === 401 || $status === 403 => 'credentials',
                $status !== null && ($status >= 500 || $status === 429) => 'server',
                $exception instanceof InvalidResponseBodyException, $exception instanceof JsonDecodingException => 'invalid_response',
                $code === 'index_not_found' => 'index_missing',
                default => 'api_configuration',
            };
            $context = ['category' => $category, 'exception_class' => $exception::class,
                'http_status' => $status, 'error_code' => $code, 'error_type' => $type];
            $fingerprint = json_encode($context, JSON_THROW_ON_ERROR);
            if (! isset($this->reported[$fingerprint])) {
                $this->reported[$fingerprint] = true;
                Log::warning('Meilisearch search unavailable', $context);
            }

            throw new SearchUnavailable($category);
        }
    }
}
