#!/usr/bin/php
<?php

/**
 * Camunda Connector
 *
 * BPM-to-RabbitMQ
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
use Kubia\Camunda\CamundaConnectorIn;

// Config
$config = __DIR__ . '/config.php';
$config_env = __DIR__ . '/config.env.php';
if (is_file($config)) {
    require_once $config;
} elseif (is_file($config_env)) {
    require_once $config_env;
}

// Config
$camundaConfig = [
    'apiUrl'       => CAMUNDA_API_URL,
    'apiLogin'     => CAMUNDA_API_LOGIN,
    'apiPass'      => CAMUNDA_API_PASS,
    'topic'        => CAMUNDA_CONNECTOR_TOPIC,
    'lockDuration' => CAMUNDA_CONNECTOR_LOCK_DURATION,
    'retries'      => CAMUNDA_CONNECTOR_DEFAULT_RETRIES,
    'retryTimeout' => CAMUNDA_CONNECTOR_DEFAULT_RETRY_TIMEOUT,
    'tickTimeout'  => CAMUNDA_TICK_TIMEOUT,
];
$rmqConfig = [
    'host'             => RMQ_HOST,
    'port'             => RMQ_PORT,
    'user'             => RMQ_USER,
    'pass'             => RMQ_PASS,
    'queue'            => RMQ_QUEUE_OUT,
    'tickTimeout'      => RMQ_TICK_TIMEOUT,
    'reconnectTimeout' => RMQ_RECONNECT_TIMEOUT,
    'vhost'            => RMQ_VHOST,
    'queueLog'         => RMQ_QUEUE_LOG,
    'vhostLog'         => RMQ_VHOST_LOG,
    'userLog'          => RMQ_USER_LOG,
    'passLog'          => RMQ_PASS_LOG
];

// Run worker
$worker = new CamundaConnectorIn($camundaConfig, $rmqConfig);
$worker->run();
