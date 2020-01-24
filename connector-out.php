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
use Kubia\Camunda\CamundaConnectorOut;

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
    'apiUrl'   => CAMUNDA_API_URL,
    'apiLogin' => CAMUNDA_API_LOGIN,
    'apiPass'  => CAMUNDA_API_PASS
];
$rmqConfig = [
    'host'             => RMQ_HOST,
    'port'             => RMQ_PORT,
    'user'             => RMQ_USER,
    'pass'             => RMQ_PASS,
    'vhost'            => RMQ_VHOST,
    'queue'            => RMQ_QUEUE_OUT,
    'tickTimeout'      => RMQ_TICK_TIMEOUT,
    'reconnectTimeout' => RMQ_RECONNECT_TIMEOUT,
    'queueLog'         => RMQ_QUEUE_LOG,
    'vhostLog'         => RMQ_VHOST_LOG,
    'userLog'          => RMQ_USER_LOG,
    'passLog'          => RMQ_PASS_LOG,
    'logging'          => LOGGING
];

// Run worker
$worker = new CamundaConnectorOut($camundaConfig, $rmqConfig);
$worker->run();

