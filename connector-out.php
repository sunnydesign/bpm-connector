#!/usr/bin/php
<?php

/**
 * Camunda Connector Out
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
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
 * Callback
 *
 * @param $msg
 */
$callback = function($msg) {
    Logger::log(sprintf("Received %s", $msg->body), 'input', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );

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

// Open connection
$connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
$channel = $connection->channel();
$channel->confirm_select(); // change channel mode to confirm mode
$channel->queue_declare(RMQ_QUEUE_OUT, false, true, false, false);
$channel->basic_consume(RMQ_QUEUE_OUT, '', false, true, false, false, $callback);

// Variate timeout for reboot worker in random time
$timeout = mt_rand(36000, 50400);

Logger::log('Waiting for messages. To exit press CTRL+C', 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 0);

try {
    while (count($channel->callbacks)) {
        $channel->wait(null, false, $timeout);
    }
} catch(Exception $e) {
    Logger::log('Planned reboot by timeout.', 'output', RMQ_QUEUE_OUT,'bpm-connector-out', 0 );
}

// Close connection
$channel->close();
$connection->close();
