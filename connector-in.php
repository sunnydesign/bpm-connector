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

    /** @var array **/
    protected $incomingParams = [
        [
            'name'     => 'queue',
            'required' => true,
            'default'  => false
        ],
        [
            'name'     => 'vhost',
            'required' => false,
            'default'  => RMQ_VHOST
        ],
        [
            'name'     => 'retries',
            'required' => false,
            'default'  => CAMUNDA_CONNECTOR_DEFAULT_RETRIES
        ],
        [
            'name'     => 'retryTimeout',
            'required' => false,
            'default'  => CAMUNDA_CONNECTOR_DEFAULT_RETRY_TIMEOUT
        ],
    ];

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {

        $this->initSignalHandler();
        $this->getOptions();
        $this->workerId = 'worker' . getmypid();
        $this->externalTaskService = new ExternalTaskService($this->camundaUrl);

        Logger::log('Waiting for task. To exit press CTRL+C', '-', '-','bpm-connector-in', 0);

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
                    'bpm-connector-in',
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
        $this->camundaUrl = sprintf(CAMUNDA_API_URL, CAMUNDA_API_LOGIN, CAMUNDA_API_PASS); // camunda api with basic auth
        $this->externalTaskTopic = CAMUNDA_CONNECTOR_TOPIC;

        $methodName = $this->topicNameToMethodName($this->externalTaskTopic);
        if (!method_exists($this, $methodName)) {
            fwrite(STDERR, "Error: Wrong value for --task-topic. Method $methodName does not exist.\n");
            exit(1);
        }
    }

    /**
     * Error: param not set
     *
     * @param $paramName
     */
    protected function paramNotSet($paramName): void
    {
        $message = '`' . $paramName . '` param not set in connector';
        Logger::log($message, 'input', '-','bpm-connector-in', 1);
        exit(1);
    }

    /**
     * Fetch and assign Camunda params
     *
     * @param object $externalTask
     * @return array
     */
    protected function assignCamundaParams($externalTask): array
    {
        foreach ($this->incomingParams as $key => $param) {
            // check isset param
            if(!isset($externalTask->variables->{$param['name']})) {
                // if param is required
                if($param['required']) {
                    $this->paramNotSet($param['name']); // error & exit
                } else {
                    $this->incomingParams[$key]['value'] = $this->incomingParams[$key]['default'];
                }
            } else {
                $this->incomingParams[$key]['value'] = $externalTask->variables->{$param['name']}->value;
            }
        }

        return $this->incomingParams;
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
        // Fetch and assign Camunda params
        $this->assignCamundaParams($externalTask);

        // incoming message from rabbit mq
        $incomingMessageAsString = $externalTask->variables->message->value ?? json_encode(['data'=>'', 'headers'=>'']);
        $incomingMessage = json_decode($incomingMessageAsString, true);

        // add external task id, process instance id, worker id in headers
        $camundaHeaders = [
            'camundaExternalTaskId'    => $externalTask->id,
            'camundaProcessInstanceId' => $externalTask->processInstanceId,
            'camundaWorkerId'          => $this->workerId,
            'camundaRetries'           => $externalTask->retries ?? $this->incomingParams['retries']['value'],
            'camundaRetryTimeout'      => $this->incomingParams['retryTimeout']['value']
        ];
        $incomingMessage['headers'] = array_merge($incomingMessage['headers'], $camundaHeaders);

        // Open connection
        $connection = new AMQPStreamConnection(RMQ_HOST, RMQ_PORT, RMQ_USER, RMQ_PASS, $this->incomingParams['vhost']['value'], false, 'AMQPLAIN', null, 'en_US', 3.0, 3.0, null, true, 60);
        $channel = $connection->channel();
        $channel->confirm_select(); // change channel mode
        $channel->queue_declare($this->incomingParams['queue']['value'], false, true, false, false);

        // send message
        $message = json_encode($incomingMessage);
        $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
        $channel->basic_publish($msg, '', $this->incomingParams['queue']['value']);

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
