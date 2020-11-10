<?php
declare(strict_types=1);

namespace Daktela\DaktelaV6;

use Daktela\DaktelaV6\Http\ApiCommunicator;
use Daktela\DaktelaV6\Http\Communicator;
use Daktela\DaktelaV6\Request\ARequest;
use Daktela\DaktelaV6\Request\CreateRequest;
use Daktela\DaktelaV6\Request\DeleteRequest;
use Daktela\DaktelaV6\Request\ReadRequest;
use Daktela\DaktelaV6\Request\UpdateRequest;
use Daktela\DaktelaV6\Response\Response;

class Client
{
    private static $singletons = [];
    const READ_LIMIT = 999;
    private $instance = null;
    private $accessToken = null;
    private $apiCommunicator = null;

    public function __construct($instance, $accessToken)
    {
        $this->instance = $instance;
        $this->accessToken = $accessToken;
        $this->apiCommunicator = ApiCommunicator::getInstance($instance, $accessToken);
    }

    public static function getInstance($instance, $accessToken)
    {
        $key = md5($instance . $accessToken);
        if (!isset(self::$singletons[$key])) {
            self::$singletons[$key] = new Client($instance, $accessToken);
        }
        return self::$singletons[$key];
    }

    public function execute(ARequest $request): Response
    {
        if ($request->isExecuted()) {
            return $request->getResponse();
        }

        if ($request instanceof UpdateRequest) {
            return $this->executeUpdate($request);
        } elseif ($request instanceof CreateRequest) {
            return $this->executeCreate($request);
        } elseif ($request instanceof DeleteRequest) {
            return $this->executeDelete($request);
        } elseif ($request instanceof ReadRequest) {
            switch ($request->getRequestType()) {
                case ReadRequest::TYPE_MULTIPLE:
                    return $this->executeReadMultiple($request);
                case ReadRequest::TYPE_SINGLE:
                    return $this->executeReadSingle($request);
                case ReadRequest::TYPE_ALL:
                    return $this->executeReadAll($request);
            }
        }
        return new Response(null, 0, ['Unknown request type'], 0);
    }

    private function executeCreate(CreateRequest $request): Response
    {
        return $this->apiCommunicator->sendRequest("POST", $request->getModel(), [], $request->getAttributes());
    }

    private function executeUpdate(UpdateRequest $request)
    {
        if (is_null($request->getObjectName()) || empty($request->getObjectName())) {
            return new Response(null, -1, ['No object name specified'], 0);
        }
        return $this->apiCommunicator->sendRequest("PUT", $request->getModel() . "/" . $request->getObjectName(), [], $request->getAttributes());
    }

    private function executeDelete(DeleteRequest $request): Response
    {
        if (is_null($request->getObjectName()) || empty($request->getObjectName())) {
            return new Response(null, -1, ['No object name specified'], 0);
        }
        return $this->apiCommunicator->sendRequest("DELETE", $request->getModel() . "/" . $request->getObjectName());
    }

    private function executeReadMultiple(ReadRequest $request): Response
    {
        $queryParams = ['skip' => $request->getSkip(), 'take' => $request->getTake(), 'filter' => $request->getFilters(), 'sort' => $request->getSorts()];
        return $this->apiCommunicator->sendRequest("GET", $request->getModel(), $queryParams);
    }

    private function executeReadAll(ReadRequest $request): Response
    {
        $response = new Response();
        for ($i = 0; $i < self::READ_LIMIT; $i++) {
            $queryParams = ['skip' => ($i * $request->getTake()), 'take' => $request->getTake(), 'filter' => $request->getFilters(), 'sort' => $request->getSorts()];
            $currentResponse = $this->apiCommunicator->sendRequest("GET", $request->getModel(), $queryParams);

            if (!empty($currentResponse->getErrors()) && !$request->isSkipErrorRequests()) {
                return $currentResponse;
            }

            $data = array_merge($response->getData(), $currentResponse->getData());
            $response = new Response($data, $currentResponse->getTotal(), $currentResponse->getErrors(), $currentResponse->getHttpStatus());

            //If returned less than take, it is the last page
            if (count($currentResponse->getData()) < $request->getTake()) {
                break;
            }
        }
        return $response;
    }

    private function executeReadSingle(ReadRequest $request): Response
    {
        if (is_null($request->getObjectName()) || empty($request->getObjectName())) {
            return new Response(null, -1, ['No object name specified'], 0);
        }
        return $this->apiCommunicator->sendRequest("GET", $request->getModel() . "/" . $request->getObjectName());
    }
}