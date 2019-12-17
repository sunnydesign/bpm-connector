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

const WAIT_BEFORE_RECONNECT_US = 1000;

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
 *
 * @param $externalTaskService
 * @param $message
 * @param $updateVariables
 */
function good_work($externalTaskService, $message, $updateVariables) {
    $externalTaskRequest = (new ExternalTaskRequest())
        ->set('variables', $updateVariables)
        ->set('workerId', $message['headers']['camundaWorkerId']);

    $externalTaskService->complete($message['headers']['camundaExternalTaskId'], $externalTaskRequest);
    Logger::log(sprintf("Completed task <%s>", $message['headers']['camundaExternalTaskId']), 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
};

/**
 * Bad work
 *
 * @param $externalTaskService
 * @param $message
 */
function bad_work($externalTaskService, $message) {
    $retries = (int)$message['headers']['camundaRetries'] ?? 0;
    $retryTimeout = (int)$message['headers']['camundaRetryTimeout'] ?? 0;

    $externalTaskRequest = (new ExternalTaskRequest())
        ->set('errorMessage', "Worker task fatal error")
        ->set('retries', $retries - 1)
        ->set('retryTimeout', $retryTimeout)
        ->set('workerId', $message['headers']['camundaWorkerId']);

    $externalTaskService->handleFailure($message['headers']['camundaExternalTaskId'], $externalTaskRequest);
    Logger::log(sprintf("Error in task <%s>", $message['headers']['camundaExternalTaskId']), 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
};

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
            'type' => 'String'
        ]
    ];

    // Request to Camunda
    $externalTaskService = new ExternalTaskService(CAMUNDA_API_URL);

    // Validate message
    validate_message($message);

    // Сomplete task if his status is success
    // and retry it if is not succcess
    $success = $message['headers']['success'] ?? false;

    if($success) {
        // GOOD WORK
        good_work($externalTaskService, $message, $updateVariables);
    } else {
        // BAD WORK
        bad_work($externalTaskService, $message);
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

        echo ' [*] Waiting for messages. To exit press CTRL+C', "\n";

        // Your application code goes here.
        $channel = $connection->channel();
        $channel->confirm_select(); // change channel mode to confirm mode
        $channel->basic_qos(0, 1, false); // one message in one loop
        $channel->basic_consume(RMQ_QUEUE_OUT, '', false, true, false, false, $callback);

        while ($channel->is_consuming()) {
            $channel->wait(null, true, 0);
            sleep(1);
        }

    } catch(AMQPRuntimeException $e) {
        echo $e->getMessage() . PHP_EOL;
        cleanup_connection($connection);
        usleep(WAIT_BEFORE_RECONNECT_US);
    } catch(\RuntimeException $e) {
        echo "Runtime exception " . PHP_EOL;
        cleanup_connection($connection);
        usleep(WAIT_BEFORE_RECONNECT_US);
    } catch(\ErrorException $e) {
        echo "Error exception " . PHP_EOL;
        cleanup_connection($connection);
        usleep(WAIT_BEFORE_RECONNECT_US);
    }
}