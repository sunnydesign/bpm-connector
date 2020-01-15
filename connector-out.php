#!/usr/bin/php
<?php

/**
 * Camunda Connector Out
 *
 * RabbitMQ-to-BPM
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
use Camunda\Entity\Request\ProcessInstanceRequest;
use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ProcessInstanceService;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
use PhpAmqpLib\Message\AMQPMessage;
use Quancy\Logger\Logger;

// Config
$config = __DIR__ . '/config.php';
$config_env = __DIR__ . '/config.env.php';
if (is_file($config)) {
    require_once $config;
} elseif (is_file($config_env)) {
    require_once $config_env;
}

/**
 * Class ConnectorOut
 */
class CamundaConnectorOut
{
    /** @var PhpAmqpLib\Connection\AMQPStreamConnection */
    private $connection;

    /** @var PhpAmqpLib\Channel\AMQPChannel  */
    private $channel;

    /** @var string */
    private $camundaUrl;

    /** @var object */
    private $processVariables;

    /** @var array */
    private $updatedVariables;

    /** @var array */
    private $message;

    /** @var array */
    private $headers;

    /** @var array Unsafe parameters in headers **/
    private $unsafeHeadersParams = [
        'camundaWorkerId',
        'camundaExternalTaskId'
    ];

    function __construct(&$connection)
    {
        $this->camundaUrl = sprintf(CAMUNDA_API_URL, CAMUNDA_API_LOGIN, CAMUNDA_API_PASS); // camunda api with basic auth
        $this->connection = $connection;
        $this->channel = $this->connection->channel();
    }

    /**
     * Get process variables
     *
     * @param $processInstanceId
     * @return bool
     */
    function getProcessVariables($processInstanceId): bool
    {
        // Get process variables request
        $getVariablesRequest = (new ProcessInstanceRequest())
            ->set('deserializeValues', false);

        $getVariablesService = new ProcessInstanceService($this->camundaUrl);
        $this->processVariables = $getVariablesService->getVariableList($processInstanceId, $getVariablesRequest);

        if($getVariablesService->getResponseCode() != 200) {
            $logMessage = sprintf(
                "Process variables from process instance <%s> not received, because `%s`",
                $processInstanceId,
                $getVariablesService->getResponseContents()->message ?? 'Request error'
            );
            Logger::log($logMessage, 'input', RMQ_QUEUE_IN,'bpm-listener', 1 );

            return false;
        } else {
            return true;
        }
    }

    /**
     * Good work
     * Complete task
     *
     * @param $externalTaskService
     */
    function completeTask($externalTaskService): void
    {
        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('variables', $this->updatedVariables)
            ->set('workerId', $this->headers['camundaWorkerId']);

        $complete = $externalTaskService->complete($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        if($complete) {
            // response for synchronous queue
            $response = 'OK';

            $logMessage = sprintf(
                "Completed task <%s> of process <%s> process instance <%s> by worker <%s>",
                $this->headers['camundaExternalTaskId'],
                $this->headers['camundaProcessKey'],
                $this->headers['camundaProcessInstanceId'],
                $this->headers['camundaWorkerId']
            );
            Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
        } else {
            // error if $externalTaskService->getResponseCode() not 204
            $responseContent = (array)$externalTaskService->getResponseContents();

            // response for synchronous queue
            $response = $responseContent["type"];

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
            Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 1 );
        }

        // if is synchronous mode
        if($this->isSynchronousMode()) {
            $this->sendSynchronousResponse($response);
        }
    }

    /**
     * @param $externalTaskService
     * @param $error
     */
    public function uncompleteTask($externalTaskService, $error): void
    {
        // Check error type from headers
        if($error['type'] === 'system') {
            // if type is `system`
            // fail task
            $this->failTask($externalTaskService);
        } else {
            // if type is `business`
            // error task
            if(isset($this->headers['camundaErrorCode'])) {
                // if is synchronous mode
                if($this->isSynchronousMode()) {
                    $this->sendSynchronousResponse($error['message']);
                }

                $errorCode = $this->headers['camundaErrorCode'];
                $this->errorTask($externalTaskService, $error['message'], $errorCode);
            } else {
                $logMessage = sprintf("`%s` not set", 'camundaErrorCode');
                Logger::log(
                    $logMessage,
                    '',
                    '-',
                    'bpm-connector-out',
                    1
                );
                exit(1);
            }
        }
    }

    /**
     * Bad work
     * Error task (business error)
     *
     * @param $externalTaskService
     * @param $errorMessage
     * @param $errorCode
     */
    function errorTask($externalTaskService, $errorMessage, $errorCode): void
    {
        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('variables', $this->updatedVariables)
            ->set('errorCode', $errorCode)
            ->set('errorMessage', $errorMessage)
            ->set('workerId', $this->headers['camundaWorkerId']);
        $externalTaskService->handleError($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        $logMessage = sprintf(
            "BPM Error from task <%s> of process <%s> process instance <%s> by worker <%s>",
            $this->headers['camundaExternalTaskId'],
            $this->headers['camundaProcessKey'],
            $this->headers['camundaProcessInstanceId'],
            $this->headers['camundaWorkerId']
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
    }

    /**
     * Bad work
     * Fail task (system error)
     *
     * @param $externalTaskService
     */
    function failTask($externalTaskService): void
    {
        $retries = (int)$this->headers['camundaRetries'] ?? 0;
        $retryTimeout = (int)$this->headers['camundaRetryTimeout'] ?? 0;

        $response = 'Worker task fatal error';
        $externalTaskRequest = (new ExternalTaskRequest())
            ->set('errorMessage', $response)
            ->set('retries', $retries - 1)
            ->set('retryTimeout', $retryTimeout)
            ->set('workerId', $this->headers['camundaWorkerId']);

        // if is synchronous mode
        if($this->isSynchronousMode() && $retries - 1 === 0) {
            $this->sendSynchronousResponse($response);
        }

        $externalTaskService->handleFailure($this->headers['camundaExternalTaskId'], $externalTaskRequest);

        $logMessage = sprintf(
            "System error from task <%s> of process <%s> process instance <%s> by worker <%s>",
            $this->headers['camundaExternalTaskId'],
            $this->headers['camundaProcessKey'],
            $this->headers['camundaProcessInstanceId'],
            $this->headers['camundaWorkerId']
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
    }

    /**
     * Validate message
     */
    function validate_message(): void
    {
        // Headers
        if(!$this->headers) {
            $logMessage = '`headers` not is set in incoming message';
            Logger::log($logMessage, 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 1);
            exit(1);
        }

        // Unsafe params
        foreach ($this->unsafeHeadersParams as $paramName) {
            if(!isset($this->headers[$paramName])) {
                $logMessage = '`' . $paramName . '` param not is set in incoming message';
                Logger::log($logMessage, 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 1);
                exit(1);
            }
        }
    }

    /**
     * If is synchronous request
     *
     * @return bool
     */
    function isSynchronousMode(): bool
    {
        return
            property_exists($this->processVariables,'rabbitCorrelationId') &&
            property_exists($this->processVariables,'rabbitCorrelationReplyTo');
    }

    /**
     * Send synchronous response
     *
     * @param $response
     */
    function sendSynchronousResponse($response): void
    {
        $correlation_id = $this->processVariables->rabbitCorrelationId->value;
        $reply_to = $this->processVariables->rabbitCorrelationReplyTo->value;

        $msg = new AMQPMessage($response, ['correlation_id' => $correlation_id]);
        $this->channel->basic_publish($msg, '', $reply_to);
    }

    /**
     * Clean parameters from message
     */
    function removeParamsFromMessage(): void
    {
        if(isset($this->message['data']) && isset($this->message['data']['parameters'])) {
            unset($this->message['data']['parameters']);
        }
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
     * Close connection
     */
    function cleanup_connection(): void
    {
        // Connection might already be closed.
        // Ignoring exceptions.
        try {
            if($this->connection !== null) {
                $this->connection->close();
            }
        } catch (\ErrorException $e) {
        }
    }

    /**
     * Shutdown
     */
    function shutdown(): void
    {
        $this->connection->close();
    }

    /**
     * Callback
     *
     * @param $msg
     */
    function callback($msg) {
        Logger::log(sprintf("Received %s", $msg->body), 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );

        // Set manual acknowledge for received message
        //$msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message
        $this->channel->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message

        // Update variables
        $this->message = json_decode($msg->body, true);
        $this->headers = $this->message['headers'] ?? null;

        // get process variables
        $this->getProcessVariables($this->headers['camundaProcessInstanceId']);

        // update rmq message in process variables
        $this->updatedVariables = (array)$this->processVariables;
        $this->updatedVariables['message'] = [
            'value' => $msg->body,
            'type' => 'Json'
        ];

        // Request to Camunda
        $externalTaskService = new ExternalTaskService($this->camundaUrl);

        // Validate message
        $this->validate_message();

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

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {
        while(true) {
            try {
                register_shutdown_function([$this, 'shutdown']);

                Logger::log('Waiting for messages. To exit press CTRL+C', 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0);

                $this->channel = $this->connection->channel();
                $this->channel->confirm_select(); // change channel mode to confirm mode
                $this->channel->basic_qos(0, 1, false); // one message in one loop
                $this->channel->basic_consume(RMQ_QUEUE_OUT, '', false, false, false, false, [$this, 'callback']);

                while ($this->channel->is_consuming()) {
                    $this->channel->wait(null, true, 0);
                    usleep(RMQ_TICK_TIMEOUT);
                }

            } catch(AMQPRuntimeException $e) {
                echo $e->getMessage() . PHP_EOL;
                $this->cleanup_connection();
                usleep(RMQ_RECONNECT_TIMEOUT);
            } catch(\RuntimeException $e) {
                echo "Runtime exception " . $e->getMessage() . PHP_EOL;
                $this->cleanup_connection();
                usleep(RMQ_RECONNECT_TIMEOUT);
            } catch(\ErrorException $e) {
                echo "Error exception " . $e->getMessage() . PHP_EOL;
                $this->cleanup_connection();
                usleep(RMQ_RECONNECT_TIMEOUT);
            }
        }
    }
}

$connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
$worker = new CamundaConnectorOut($connection);
$worker->run();