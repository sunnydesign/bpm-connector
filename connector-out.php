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
use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exception\AMQPRuntimeException;
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
 * Good work
 * Complete task
 *
 * @param $externalTaskService
 * @param $message
 * @param $updateVariables
 */
function completeTask($externalTaskService, $message, $updateVariables) {
    $headers = $message['headers'];

    $externalTaskRequest = (new ExternalTaskRequest())
        ->set('variables', $updateVariables)
        ->set('workerId', $headers['camundaWorkerId']);

    $complete = $externalTaskService->complete($headers['camundaExternalTaskId'], $externalTaskRequest);

    if($complete) {
        $logMessage = sprintf(
            "Completed task <%s> of process <%s> process instance <%s> by worker <%s>",
            $headers['camundaExternalTaskId'],
            $headers['camundaProcessKey'],
            $headers['camundaProcessInstanceId'],
            $headers['camundaWorkerId']
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
    } else {
        // error if $externalTaskService->getResponseCode() not 204
        $responseContent = (array) $externalTaskService->getResponseContents();
        if(isset($responseContent["type"]) && isset($responseContent["message"])) {
            $responseContentCombined = sprintf(
                "type <%s> and message <%s>",
                $responseContent["type"],
                $responseContent["message"]
            );
        }
        $logMessage = sprintf(
            "Task <%s> of process <%s> process instance <%s> by worker <%s> not completed. Api return code <%s> with error %s",
            $headers['camundaExternalTaskId'],
            $headers['camundaProcessKey'],
            $headers['camundaProcessInstanceId'],
            $headers['camundaWorkerId'],
            $externalTaskService->getResponseCode(),
            $responseContentCombined ?? ""
        );
        Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 1 );
    }
};

/**
 * Bad work
 * Error task
 *
 * @param $externalTaskService
 * @param $message
 * @param $updateVariables
 * @param $errorMessage
 * @param $errorCode
 */
function errorTask($externalTaskService, $message, $updateVariables, $errorMessage, $errorCode) {
    $headers = $message['headers'];

    $externalTaskRequest = (new ExternalTaskRequest())
        ->set('variables', $updateVariables)
        ->set('errorCode', $errorCode)
        ->set('errorMessage', $errorMessage)
        ->set('workerId', $headers['camundaWorkerId']);
    $externalTaskService->handleError($headers['camundaExternalTaskId'], $externalTaskRequest);

    $logMessage = sprintf(
        "BPM Error from task <%s> of process <%s> process instance <%s> by worker <%s>",
        $headers['camundaExternalTaskId'],
        $headers['camundaProcessKey'],
        $headers['camundaProcessInstanceId'],
        $headers['camundaWorkerId']
    );
    Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
};

/**
 * Fail work
 * Fail task
 *
 * @param $externalTaskService
 * @param $message
 */
function failTask($externalTaskService, $message) {
    $headers = $message['headers'];
    $retries = (int)$headers['camundaRetries'] ?? 0;
    $retryTimeout = (int)$headers['camundaRetryTimeout'] ?? 0;

    $externalTaskRequest = (new ExternalTaskRequest())
        ->set('errorMessage', "Worker task fatal error")
        ->set('retries', $retries - 1)
        ->set('retryTimeout', $retryTimeout)
        ->set('workerId', $headers['camundaWorkerId']);

    $externalTaskService->handleFailure($headers['camundaExternalTaskId'], $externalTaskRequest);

    $logMessage = sprintf(
        "System error from task <%s> of process <%s> process instance <%s> by worker <%s>",
        $headers['camundaExternalTaskId'],
        $headers['camundaProcessKey'],
        $headers['camundaProcessInstanceId'],
        $headers['camundaWorkerId']
    );
    Logger::log($logMessage, 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
}

/**
 * Validate message
 */
function validate_message($message) {
    // Headers
    if(!isset($message['headers'])) {
        $message = '`headers` not is set in incoming message';
        Logger::log($message, 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 1);
        exit(1);
    }

    // Unsafe parameters in headers
    $unsafeHeadersParams = ['camundaWorkerId', 'camundaExternalTaskId'];

    foreach ($unsafeHeadersParams as $paramName) {
        if(!isset($message['headers'][$paramName])) {
            $message = '`' . $paramName . '` param not is set in incoming message';
            Logger::log($message, 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 1);
            exit(1);
        }
    }
}
/**
 * Close connection
 *
 * @param $connection
 */
function cleanup_connection($connection) {
    // Connection might already be closed.
    // Ignoring exceptions.
    try {
        if($connection !== null) {
            $connection->close();
        }
    } catch (\ErrorException $e) {
    }
}

/**
 * Shutdown
 *
 * @param $connection
 */
function shutdown($connection)
{
    //$channel->close();
    $connection->close();
}


/**
 * Callback
 *
 * @param $msg
 */
$callback = function($msg) {
    Logger::log(sprintf("Received %s", $msg->body), 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );

    // Set manual acknowledge for received message
    $msg->delivery_info['channel']->basic_ack($msg->delivery_info['delivery_tag']); // manual confirm delivery message
    // Send a message with the string "quit" to cancel the consumer.
    if ($msg->body === 'quit') {
        $msg->delivery_info['channel']->basic_cancel($msg->delivery_info['consumer_tag']);
    }

    // Update variables
    $message = json_decode($msg->body, true);
    $updateVariables = [
        'message' => [
            'value' => $msg->body,
            'type' => 'Json'
        ]
    ];

    // Request to Camunda
    $camundaUrl = sprintf(CAMUNDA_API_URL, CAMUNDA_API_LOGIN, CAMUNDA_API_PASS); // camunda api with basic auth
    $externalTaskService = new ExternalTaskService($camundaUrl);

    // Validate message
    validate_message($message);

    // Сomplete task if his status is success
    // and retry it if is not succcess
    $success = $message['headers']['success'] ?? false;

    if($success) {
        // GOOD WORK
        // Clean parameters
        if(isset($message['data']) && isset($message['data']['parameters'])) {
            unset($message['data']['parameters']);
        }

        completeTask($externalTaskService, $message, $updateVariables);
    } else {
        // BAD WORK
        $errorType = 'business';
        $errorMessage = 'Unknown error';
        $errorCode = $message['headers']['camundaErrorCode']; // @todo check it

        if(isset($message['headers']['error'])) {
            $errorType = $message['headers']['error']['errorType'] ?? 'business';
            $errorMessage = $message['headers']['error']['errorMessage'] ?? 'Unknown error';
        }

        // Check errorType from headers
        // if type is `system`
        if($errorType === 'system')
            failTask($externalTaskService, $message);
        else
            errorTask($externalTaskService, $message, $updateVariables, $errorMessage, $errorCode);
    }

};

/**
 * Loop
 */
$connection = null;
while(true) {
    try {
        $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
        register_shutdown_function('shutdown', $connection);

        Logger::log('Waiting for messages. To exit press CTRL+C', 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0);

        // Your application code goes here.
        $channel = $connection->channel();
        $channel->confirm_select(); // change channel mode to confirm mode
        $channel->basic_qos(0, 1, false); // one message in one loop
        $channel->basic_consume(RMQ_QUEUE_OUT, '', false, false, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait(null, true, 0);
            usleep(RMQ_TICK_TIMEOUT);
        }

    } catch(AMQPRuntimeException $e) {
        echo $e->getMessage() . PHP_EOL;
        cleanup_connection($connection);
        usleep(RMQ_RECONNECT_TIMEOUT);
    } catch(\RuntimeException $e) {
        echo "Runtime exception " . PHP_EOL;
        cleanup_connection($connection);
        usleep(RMQ_RECONNECT_TIMEOUT);
    } catch(\ErrorException $e) {
        echo "Error exception " . PHP_EOL;
        cleanup_connection($connection);
        usleep(RMQ_RECONNECT_TIMEOUT);
    }
}