<?php

namespace Kubia\Camunda;

use Camunda\Entity\Request\ExternalTaskRequest;
use Camunda\Service\ExternalTaskService;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;
use Kubia\Logger\Logger;
use Exception;

/**
 * Class CamundaConnectorIn
 * @package Kubia\Camunda
 */
class CamundaConnectorIn
{
    /** @var string */
    public $camundaUrl;

    /** @var \PhpAmqpLib\Connection\AMQPStreamConnection */
    public $connection;

    /** @var \PhpAmqpLib\Channel\AMQPChannel */
    public $channel;

    /** @var string */
    public $externalTaskTopic;

    /** @var int */
    public $lockDuration = 600000;

    /** @var string */
    public $workerId;

    /** @var Camunda\Service\ExternalTaskService */
    public $externalTaskService;

    /** @var json string */
    public $incomingMessage;

    /** @var array **/
    public $incomingParams = [
        'command' => [
            'name'      => 'command',
            'required'  => true,
            'default'   => null
        ],
        'queue' => [
            'name'      => 'queue',
            'required'  => true,
            'default'   => null
        ],
        'vhost' => [
            'name'      => 'vhost',
            'required'  => false,
            'default'   => 'localhost'
        ],
        'retries' => [
            'name'      => 'retries',
            'required'  => false,
            'default'   => 0
        ],
        'retryTimeout' => [
            'name'      => 'retryTimeout',
            'required'  => false,
            'default'   => 1000
        ],
        'response_to' => [
            'name'      => 'response_to',
            'required'  => false,
            'default'   => null
        ],
        'response_command' => [
            'name'      => 'response_command',
            'required'  => false,
            'default'   => null
        ],
        'errorCode' => [
            'name'      => 'errorCode',
            'required'  => false,
            'default'   => null
        ],
        'errorRetries' => [
            'name'      => 'errorRetries',
            'required'  => false,
            'default'   => null
        ],
    ];

    /** @var array */
    public $camundaConfig = [];

    /** @var array */
    public $rmqConfig = [];

    /**
     * CamundaConnectorIn constructor.
     * @param array $camundaConfig
     * @param array $rmqConfig
     */
    public function __construct(array $camundaConfig, array $rmqConfig)
    {
        $this->initSignalHandler();
        $this->workerId = 'worker' . getmypid();
        $this->camundaConfig = $camundaConfig;
        $this->rmqConfig = $rmqConfig;
        $this->lockDuration = $camundaConfig['lockDuration'];

        // fix incoming params default values
        $this->incomingParams['vhost']['default'] = $this->rmqConfig['vhost'];
        $this->incomingParams['retries']['default'] = $this->camundaConfig['retries'];
        $this->incomingParams['retryTimeout']['default'] = $this->camundaConfig['retryTimeout'];
        $this->incomingParams['response_to']['default'] = $this->rmqConfig['queue'];

        // connect to camunda api with basic auth
        $this->camundaUrl = sprintf($this->camundaConfig['apiUrl'], $this->camundaConfig['apiLogin'], $this->camundaConfig['apiPass']);
        $this->externalTaskTopic = $this->camundaConfig['topic'];
        $this->externalTaskService = new ExternalTaskService($this->camundaUrl);
    }

    /**
     * Initialize and run in endless loop
     */
    public function run(): void
    {
        Logger::stdout('Waiting for task. To exit press CTRL+C', '-', '-','bpm-connector-in', 0);

        while (true) {
            // Quit on Ctrl+C
            pcntl_signal_dispatch();
            foreach ($this->fetchExternalTasks() as $externalTask) {
                call_user_func([$this, 'handleTask'], $externalTask);
            }
            usleep($this->camundaConfig['tickTimeout']);
        }
    }

    /**
     * Fetch and lock external tasks from Camunda
     * @return array
     */
    public function fetchExternalTasks(): array
    {
        // Fetch one external task of the given topic
        $topics = [[
            'topicName' => $this->externalTaskTopic,
            'lockDuration' => $this->lockDuration
        ]];
        $externalTaskQueryRequest = (new ExternalTaskRequest())
            ->set('topics', $topics)
            ->set('workerId', $this->workerId)
            ->set('maxTasks', 1);
        $result = $this->externalTaskService->fetchAndLock($externalTaskQueryRequest);
        if (!is_array($result)) {
            $result = [];
        }
        return $result;
    }

    /**
     * Initialize Unix signal handler
     * ... to make sure we quit on Ctrl+C whenever we call pcntl_signal_dispatch().
     */
    public function initSignalHandler(): void
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
     * Error: param not set
     * @param $paramName
     */
    public function paramNotSet($paramName, $externalTask): void
    {
        $logMessage = sprintf(
            "`%s` param not set from topic <%s> task <%s> of process <%s> process instance <%s> ",
            $paramName,
            $externalTask->topicName,
            $externalTask->id,
            $externalTask->processDefinitionKey,
            $externalTask->processInstanceId
        );
        Logger::stdout(
            $logMessage,
            '',
            '-',
            'bpm-connector-in',
            1
        );
        // @todo need task id and process variables or process id from variables
        if($this->rmqConfig['logging']) {
            Logger::elastic('bpm',
                'in progress',
                'error',
                (object)[],
                (object)[],
                ['type' => 'system', 'message' => $logMessage],
                $this->channelLog,
                $this->rmqConfig['queueLog']
            );
        }
        exit(1);
    }

    /**
     * If parameter is reserved:
     * Belong to $this->incomingParams or if is `message`
     * @param string $key
     * @return bool
     */
    public function paramIsReserved($key): bool
    {
        $isIncomingParam = array_key_exists($key, (array) $this->incomingParams);
        $isMessage = $key === 'message';

        return $isIncomingParam || $isMessage;
    }

    /**
     * Fetch and assign Camunda Unsafe params
     * @param object $externalTask
     * @return array
     */
    public function assignCamundaUnsafeParams($externalTask): array
    {
        foreach ($this->incomingParams as $key => $param) {
            // Check isset param
            if(!isset($externalTask->variables->{$param['name']})) {
                // If param is required
                if($param['required']) {
                    $this->paramNotSet($param['name'], $externalTask); // error & exit
                } else {
                    // If default not null
                    if($this->incomingParams[$key]['default'] !== null) {
                        $this->incomingParams[$key]['value'] = $this->incomingParams[$key]['default'];
                    }
                }
            } else {
                // Assign param
                $this->incomingParams[$key]['value'] = $externalTask->variables->{$param['name']}->value;
            }
        }

        return $this->incomingParams;
    }

    /**
     * Fetch and assign Camunda Safe params
     * @param object $externalTask
     * @return array
     */
    public function assignCamundaSafeParams($externalTask): array
    {
        // Add `data.parameters` if is not set
        if(!isset($this->incomingMessage['data']['parameters'])) {
            $this->incomingMessage['data']['parameters'] = [];
        }

        // Set safe variables in `data.parameters`
        foreach((array)$externalTask->variables as $key => $variable) {
            // If parameter is not reserved
            if(!$this->paramIsReserved($key)) {
                $this->incomingMessage['data']['parameters'][$key] = $variable->value;
            }
        }

        return $this->incomingMessage;
    }

    /**
     * Set Camunda params in headers
     * @param object $externalTask
     * @return array
     */
    public function setHeadersFromIncomingParams($externalTask): array
    {
        // @todo:need refactoring

        // Add external task id, process instance id, worker id in headers
        $camundaHeaders = [
            'camundaExternalTaskId'    => $externalTask->id,
            'camundaProcessInstanceId' => $externalTask->processInstanceId,
            'camundaWorkerId'          => $this->workerId,
            'camundaRetries'           => $externalTask->retries ?? $this->incomingParams['retries']['value'],
            'camundaRetryTimeout'      => $this->incomingParams['retryTimeout']['value']
        ];

        // Add `camundaErrorCode`
        if(isset($this->incomingParams['errorCode']['value'])) {
            $camundaHeaders['camundaErrorCode'] = $this->incomingParams['errorCode']['value'];
        }

        // Add `camundaErrorCounter` if is not set
        if(
            isset($this->incomingParams['errorRetries']['value']) &&
            !isset($this->incomingMessage['headers']['camundaErrorCounter'])
        ) {
            $camundaHeaders['camundaErrorCounter'] = $this->incomingParams['errorRetries']['value'];
        }

        $this->incomingMessage['headers'] = array_merge($this->incomingMessage['headers'], $camundaHeaders);

        // Add `command`, `queue` and `vhost` in headers
        $this->incomingMessage['headers']['command'] = $this->incomingParams['command']['value'];
        $this->incomingMessage['headers']['queue'] = $this->incomingParams['queue']['value'];
        $this->incomingMessage['headers']['vhost'] = $this->incomingParams['vhost']['value'];

        // Add `response_to` and `response_command`
        if(isset($this->incomingParams['response_to']['value'])) {
            $this->incomingMessage['headers']['response_to'] = $this->incomingParams['response_to']['value'];
        }
        if(isset($this->incomingParams['response_command']['value'])) {
            $this->incomingMessage['headers']['response_command'] = $this->incomingParams['response_command']['value'];
        }

        // return headers only
        return $this->incomingMessage['headers'];
    }

    /**
     * Log is task fetch and lock
     * @param $externalTask
     */
    public function logFetch($externalTask): void
    {
        $logMessage = sprintf(
            "Fetched and locked from topic <%s> task <%s> of process <%s> process instance <%s>",
            $externalTask->topicName,
            $externalTask->id,
            $externalTask->processDefinitionKey,
            $externalTask->processInstanceId
        );
        Logger::stdout(
            $logMessage,
            'input',
            '-',
            'bpm-connector-in',
            0
        );
        // @todo need task id and process variables or process id from variables
        if($this->rmqConfig['logging']) {
            Logger::elastic('bpm',
                'in progress',
                'fetched',
                (object)[],
                (object)[],
                [],
                $this->channelLog,
                $this->rmqConfig['queueLog']
            );
        }
    }

    /**
     * Topic Connector
     * Send task to Rabbit MQ
     * @param $externalTask
     * @throws Exception
     */
    public function handleTask($externalTask): void
    {
        // Open connection for logging to elasticsearch
        if($this->rmqConfig['logging']) {
            $this->connectionLog = new AMQPStreamConnection(
                $this->rmqConfig['host'],
                $this->rmqConfig['port'],
                $this->rmqConfig['userLog'],
                $this->rmqConfig['passLog'],
                $this->rmqConfig['vhostLog'],
                false,
                'AMQPLAIN',
                null,
                'en_US',
                3.0,
                3.0,
                null,
                true,
                60
            );
            $this->channelLog = $this->connectionLog->channel();
            $this->channelLog->confirm_select(); // change channel mode
        }

        // Logging
        $this->logFetch($externalTask);

        // Fetch and assign Camunda unsafe params
        $this->assignCamundaUnsafeParams($externalTask);

        // Open connection
        $this->connection = new AMQPStreamConnection(
            $this->rmqConfig['host'],
            $this->rmqConfig['port'],
            $this->rmqConfig['user'],
            $this->rmqConfig['pass'],
            $this->rmqConfig['vhost'],
            false,
            'AMQPLAIN',
            null,
            'en_US',
            3.0,
            3.0,
            null,
            true,
            60
        );
        $this->channel = $this->connection->channel();
        $this->channel->confirm_select(); // change channel mode

        // Incoming message from rabbit mq
        $incomingMessageAsString = $externalTask->variables->message->value ?? json_encode(['data'=>'', 'headers'=>'']);
        $this->incomingMessage = json_decode($incomingMessageAsString, true);

        // Fetch headers params from incomingParams and put in message.headers
        $this->setHeadersFromIncomingParams($externalTask);

        // Fetch and assign Camunda safe params to message.data.parameters
        $this->assignCamundaSafeParams($externalTask);

        // Send message
        $message = json_encode($this->incomingMessage);
        $msg = new AMQPMessage($message, ['delivery_mode' => 2]);
        $this->channel->basic_publish($msg, '', $this->incomingParams['queue']['value']);

        // Close channel
        if($this->rmqConfig['logging']) {
            $this->channelLog->close();
            $this->connectionLog->close();
        }
        $this->channel->close();
        $this->connection->close();
    }
}