#!/usr/bin/php
<?php

/**
 * Camunda Connector Consumer
 */

sleep(1); // timeout for start through supervisor

require_once __DIR__ . '/vendor/autoload.php';

// Libs
use PhpAmqpLib\Connection\AMQPStreamConnection;
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

// Open connection
$connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);

/**
 * Callback
 *
 * @param $msg
 */
$callback = function($msg) use ($connection) {
    Logger::log(sprintf("Received %s", $msg->body), 'input', RMQ_QUEUE_OUT,'bpm-consumer', 0 );

    /**
     * Делаем какую-то работу,
     * по результату сообщаем в очередь,
     * которую слушает connector-out
     */
    sleep(1);

    $channel_out = $connection->channel();
    $channel_out->confirm_select();  // change channel mode to confirm mode
    $channel_out->queue_declare(RMQ_QUEUE_OUT, false, true, false, false);

    // входящее сообщение
    $incomingMessage = json_decode($msg->body, true);

    /**
     * исходящее сообщение
     * для успеха установить success в true - задача комплитится
     * для ошибки установить success в false - задача фейлится
     */
    $outgoingMessage = $incomingMessage;
    $outgoingMessage['headers']['success'] = false;

    $message = json_encode($outgoingMessage);
    $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
    $channel_out->basic_publish($msg, '', RMQ_QUEUE_OUT);
};

// Open channel
$channel = $connection->channel();
$channel->confirm_select();  // change channel mode to confirm mode
$channel->queue_declare(RMQ_QUEUE_IN, false, true, false, false);
$channel->basic_consume(RMQ_QUEUE_IN, '', false, true, false, false, $callback);

// Variate timeout for reboot worker in random time
$timeout = mt_rand(36000, 50400);

Logger::log('Waiting for messages. To exit press CTRL+C', 'input', RMQ_QUEUE_IN,'bpm-consumer', 0);

try {
    while (count($channel->callbacks)) {
        $channel->wait(null, false, $timeout);
    }
} catch(Exception $e) {
    Logger::log('Planned reboot by timeout.', 'input', RMQ_QUEUE_IN,'bpm-consumer', 0 );
}

// Close connection
$channel->close();
$connection->close();
