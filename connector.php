#!/usr/bin/php
<?php

/**
 * Camunda Connector
 */

require __DIR__ . '/vendor/autoload.php';

use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
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

class CamundaConnector
{
    /** @var string */
    protected $camundaUrl;

    /** @var string */
    protected $externalTaskTopic;

    /** @var int */
    protected $lockDuration = CAMUNDA_CONNECTOR_LOCK_DURATION;

    /** @var string */
    protected $workerId;

    /** @var ExternalTaskService */
    protected $externalTaskService;

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {

        $this->initSignalHandler();
        $this->getOptions();
        $this->workerId = 'worker' . getmypid();
        $this->externalTaskService = new ExternalTaskService($this->camundaUrl);

        Logger::log('Waiting for task. To exit press CTRL+C', '-', '-','bpm-connector', 0);

        while (true) {
            // Quit on Ctrl+C
            pcntl_signal_dispatch();
            foreach ($this->fetchExternalTasks() as $externalTask) {
                Logger::log(
                    sprintf(
                        "Fetched and locked <%s> task <%s> of <%s> process instance <%s>",
                        $externalTask->topicName,
                        $externalTask->id,
                        $externalTask->processDefinitionKey,
                        $externalTask->processInstanceId
                    ),
                    'input',
                    '-',
                    '-',
                    'bpm-connector',
                    0
                );

                call_user_func([$this, $this->topicNameToMethodName($externalTask->topicName)], $externalTask);
            }
            usleep(1000000);
        }
    }

    /**
     * Fetch and lock external tasks from Camunda
     *
     * @return array
     */
    protected function fetchExternalTasks(): array
    {
        // Fetch one external task of the given topic
        $externalTaskQueryRequest = (new ExternalTaskRequest())
            ->set('topics', [['topicName' => $this->externalTaskTopic, 'lockDuration' => $this->lockDuration]])
            ->set('workerId', $this->workerId)
            ->set('maxTasks', 1);
        $result = $this->externalTaskService->fetchAndLock($externalTaskQueryRequest);
        if (!is_array($result)) {
            $result = [];
        }
        return $result;
    }

    /**
     * Get the name of the method to use for handling an external task topic
     *
     * @param string $topicName
     * @return string
     */
    protected function topicNameToMethodName(string $topicName): string
    {
        return 'handleTask_' . strtr($topicName, ['-' => '_']);
    }

    /**
     * Initialize Unix signal handler
     *
     * ... to make sure we quit on Ctrl+C whenever we call pcntl_signal_dispatch().
     */
    protected function initSignalHandler(): void
    {
        pcntl_signal(SIGINT, function () {
            fwrite(STDERR, "Caught SIGINT - exitting\n");
            exit(128 + SIGINT);
        });
        pcntl_signal(SIGTERM, function () {
            fwrite(STDERR, "Caught SIGTERM - exitting\n");
            exit(128 + SIGTERM);
        });
    }

    /**
     * Get options
     */
    protected function getOptions(): void
    {
        $this->camundaUrl = CAMUNDA_API_URL;
        $this->externalTaskTopic = CAMUNDA_CONNECTOR_TOPIC;

        $methodName = $this->topicNameToMethodName($this->externalTaskTopic);
        if (!method_exists($this, $methodName)) {
            fwrite(STDERR, "Error: Wrong value for --task-topic. Method $methodName does not exist.\n");
            exit(1);
        }
    }

    /**
     * Topic Connector
     * Send task to Rabbit MQ
     *
     * @param object $externalTask
     * @throws Exception
     */
    protected function handleTask_connector($externalTask): void
    {
        // Camunda parameters
        $queue = $externalTask->variables->queue->value;
        $retries = $externalTask->variables->retries->value;
        $retryTimeout = $externalTask->variables->retryTimeout->value;

        // incoming message from rabbit mq
        $incomingMessageAsString = $externalTask->variables->message->value ?? json_encode(['data'=>'', 'headers'=>'']);
        $incomingMessage = json_decode($incomingMessageAsString, true);

        // add external task id, process instance id, worker id in headers
        $camundaHeaders = [
            'camundaExternalTaskId'    => $externalTask->id,
            'camundaProcessInstanceId' => $externalTask->processInstanceId,
            'camundaWorkerId'          => $this->workerId,
            'camundaRetries'           => $externalTask->retries ?? $retries,
            'camundaRetryTimeout'      => $retryTimeout,
        ];
        $incomingMessage['headers'] = array_merge($incomingMessage['headers'], $camundaHeaders);

        // Open connection
        $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, RMQ_VHOST, false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
        $channel = $connection->channel();
        $channel->confirm_select(); // change channel mode
        $channel->queue_declare($queue, false, true, false, false);

        // send message
        $message = json_encode($incomingMessage);
        $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
        $channel->basic_publish($msg, '', $queue);

        // for test
        // $channel->queue_declare(RMQ_QUEUE_ERR, false, true, false, false);
        // $channel->basic_publish($msg, '', RMQ_QUEUE_ERR);

        // close channel
        $channel->close();
        $connection->close();
    }

}

$worker = new CamundaConnector();
$worker->run();
