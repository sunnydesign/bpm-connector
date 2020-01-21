<?php

namespace Kubia\Camunda;

use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Message\AMQPMessage;
use Kubia\Logger\Logger;

/**
 * Class CamundaConnectorOut
 * @package Kubia\Camunda
 */
class CamundaConnectorOut extends CamundaBaseConnector
{
    /** @var array */
    public $unsafeHeadersParams = [
        'camundaWorkerId',
        'camundaExternalTaskId'
    ];

    /** @var string */
    public $logOwner = 'bpm-connector-out';

    /**
     * Update process variables
     */
    public function updateProcessVariables(): void
    {
        // update rmq message in process variables
        $this->updatedVariables = (array)$this->processVariables;

        // update error counter in rmq message headers
        if(isset($this->headers['camundaErrorCounter'])) {
            $this->headers['camundaErrorCounter'] = $this->headers['camundaErrorCounter'] - 1;
            $this->message['headers'] = $this->headers;
        }

        $this->updatedVariables['message'] = [
            'value' => json_encode($this->message),
            'type'  => 'Json'
        ];
    }

    /**
     * @param $externalTaskService ExternalTaskService
     */
    public function completeTask(ExternalTaskService $externalTaskService): void
    {
        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('variables', $this->updatedVariables)
            ->set('workerId', $this->headers['camundaWorkerId']);

        $complete = $externalTaskService->complete($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        if($complete) {
            // if is synchronous mode
            if($this->isSynchronousMode())
                $this->sendSynchronousResponse($this->msg, true, $this->headers['camundaProcessInstanceId']);

            $logMessage = sprintf(
                "Completed task <%s> of process <%s> process instance <%s> by worker <%s>",
                $this->headers['camundaExternalTaskId'],
                $this->headers['camundaProcessKey'],
                $this->headers['camundaProcessInstanceId'],
                $this->headers['camundaWorkerId']
            );
            Logger::log($logMessage, 'input', RMQ_QUEUE_OUT, $this->logOwner, 0 );
        } else {
            // if is synchronous mode
            if($this->isSynchronousMode())
                $this->sendSynchronousResponse($this->msg, false);

            // error if Camunda API response not 204
            $responseContent = (array)$externalTaskService->getResponseContents();

            if(isset($responseContent["type"]) && isset($responseContent["message"])) {
                $responseContentCombined = sprintf(
                    "type <%s> and message <%s>",
                    $responseContent["type"],
                    $responseContent["message"]
                );
            }
            $logMessage = sprintf(
                "Task <%s> of process <%s> process instance <%s> by worker <%s> not completed. Api return code <%s> with error %s",
                $this->headers['camundaExternalTaskId'],
                $this->headers['camundaProcessKey'],
                $this->headers['camundaProcessInstanceId'],
                $this->headers['camundaWorkerId'],
                $externalTaskService->getResponseCode(),
                $responseContentCombined ?? ""
            );
            Logger::log($logMessage, 'input', RMQ_QUEUE_OUT, $this->logOwner, 1 );
        }
    }

    /**
     * Uncomplete task
     * If catch business or system error
     *
     * @param ExternalTaskService $externalTaskService
     * @param array $error
     */
    public function uncompleteTask(ExternalTaskService $externalTaskService, array $error): void
    {
        // Check error type from headers
        if($error['type'] === 'system') {
            // fail task if type is `system`
            $this->failTask($externalTaskService);
        } else {
            // error task if type is `business`
            if(isset($this->headers['camundaErrorCode'])) {
                $errorCode = $this->headers['camundaErrorCode'];
                $this->errorTask($externalTaskService, $error['message'], $errorCode);
            } else {
                $logMessage = sprintf("`%s` not set", 'camundaErrorCode');
                Logger::log(
                    $logMessage,
                    '',
                    '-',
                    $this->logOwner,
                    1
                );
                //exit(1);
            }
        }
    }

    /**
     * Bad work
     * Error task (business error)
     *
     * @param ExternalTaskService $externalTaskService
     * @param string $errorMessage
     * @param string $errorCode
     */
    public function errorTask(ExternalTaskService $externalTaskService, string $errorMessage, string $errorCode): void
    {
        // error counter decrement in process variables
        if(isset($this->headers['camundaErrorCounter'])) {
            $this->updatedVariables['errorCounter'] = [
                'value' => $this->headers['camundaErrorCounter'],
                'type'  => 'string'
            ];
        }

        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('variables', $this->updatedVariables)
            ->set('errorCode', $errorCode)
            ->set('errorMessage', $errorMessage)
            ->set('workerId', $this->headers['camundaWorkerId']);
        $externalTaskService->handleError($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        // if is synchronous mode
        if($this->isSynchronousMode()) {
            $this->requestErrorMessage = $errorMessage;
            $this->sendSynchronousResponse($this->msg, false);
        }

        $logMessage = sprintf(
            "BPM Error from task <%s> of process <%s> process instance <%s> by worker <%s>",
            $this->headers['camundaExternalTaskId'],
            $this->headers['camundaProcessKey'],
            $this->headers['camundaProcessInstanceId'],
            $this->headers['camundaWorkerId']
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT, $this->logOwner, 0 );
    }

    /**
     * Bad work
     * Fail task (system error)
     *
     * @param ExternalTaskService $externalTaskService
     */
    public function failTask(ExternalTaskService $externalTaskService): void
    {
        $retries = (int)$this->headers['camundaRetries'] ?? 1;
        $retriesRemaining = $retries - 1;
        $retryTimeout = (int)$this->headers['camundaRetryTimeout'] ?? 0;

        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('errorMessage', $this->requestErrorMessage)
            ->set('retries', $retriesRemaining)
            ->set('retryTimeout', $retryTimeout)
            ->set('workerId', $this->headers['camundaWorkerId']);

        // if is synchronous mode
        if($this->isSynchronousMode() && $retriesRemaining === 0)
            $this->sendSynchronousResponse($this->msg, false);

        $externalTaskService->handleFailure($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        $logMessage = sprintf(
            "System error from task <%s> of process <%s> process instance <%s> by worker <%s>",
            $this->headers['camundaExternalTaskId'],
            $this->headers['camundaProcessKey'],
            $this->headers['camundaProcessInstanceId'],
            $this->headers['camundaWorkerId']
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT, $this->logOwner, 0 );
    }

    /**
     * If is synchronous request
     *
     * @return bool
     */
    public function isSynchronousMode(): bool
    {
        return
            property_exists($this->processVariables,'rabbitCorrelationId') &&
            property_exists($this->processVariables,'rabbitCorrelationReplyTo');
    }

    /**
     * Send synchronous response
     *
     * @param AMQPMessage $msg
     * @param bool $success
     * @param string $processInstanceId
     */
    public function sendSynchronousResponse(AMQPMessage $msg, bool $success = false, $processInstanceId= null): void
    {
        if($success)
            $responseToSync = $this->getSuccessResponseForSynchronousRequest($processInstanceId);
        else
            $responseToSync = $this->getErrorResponseForSynchronousRequest($this->requestErrorMessage);

        $correlation_id = $this->processVariables->rabbitCorrelationId->value;
        $reply_to = $this->processVariables->rabbitCorrelationReplyTo->value;

        $sync_msg = new AMQPMessage($responseToSync, ['correlation_id' => $correlation_id]);
        $this->channel->basic_publish($sync_msg, '', $reply_to);
    }

    /**
     * Clean parameters from message
     */
    public function removeParamsFromMessage(): void
    {
        /*
         * давай пока не будем их чистить,
         * это по факту копия переменных процесса проходящих через очереди
        if(isset($this->message['data']) && isset($this->message['data']['parameters'])) {
            unset($this->message['data']['parameters']);
        }
        */
        if(isset($this->message['headers']) && isset($this->message['headers']['camundaErrorCounter'])) {
            unset($this->message['headers']['camundaErrorCounter']);
        }

        $this->updatedVariables['message'] = [
            'value' => json_encode($this->message),
            'type'  => 'Json'
        ];
    }

    /**
     * Get Error type and error message from headers
     * @return array
     */
    public function getErrorFromHeaders(): array
    {
        $error = [
            'type'    => 'business',
            'message' => 'Unknown error'
        ];

        if(isset($this->headers['error'])) {
            $error['type'] = $this->headers['error']['type'] ?? $error['type'];
            $error['message'] = $this->headers['error']['message'] ?? $error['message'];
        }

        return $error;
    }

    /**
     * Callback
     * @param AMQPMessage $msg
     */
    public function callback(AMQPMessage $msg): void
    {
        Logger::log(sprintf("Received %s", $msg->body), 'input', $this->rmqConfig['queue'], $this->logOwner, 0 );

        $this->requestErrorMessage = 'Request error';

        // Set manual acknowledge for received message
        $this->channel->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message

        $this->msg = $msg;

        // Update variables
        $this->message = json_decode($msg->body, true);
        $this->headers = $this->message['headers'] ?? null;

        // get process variables
        $this->getProcessVariables();

        // update process variables
        $this->updateProcessVariables();

        // Request to Camunda
        $externalTaskService = new ExternalTaskService($this->camundaUrl);

        // Validate message
        $this->validateMessage();

        // Сomplete task if his status is success
        // and retry it if is not succcess
        $success = $this->headers['success'] ?? false;

        if($success) {
            // GOOD WORK
            $this->removeParamsFromMessage();
            $this->completeTask($externalTaskService);
        } else {
            // BAD WORK
            $error = $this->getErrorFromHeaders();
            $this->uncompleteTask($externalTaskService, $error, $msg);
        }
    }
}