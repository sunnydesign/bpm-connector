<?php
define('CAMUNDA_API_URL', getenv('CAMUNDA_API_URL'));
define('CAMUNDA_CONNECTOR_TOPIC', getenv('CAMUNDA_CONNECTOR_TOPIC'));
define('CAMUNDA_CONNECTOR_LOCK_DURATION', getenv('CAMUNDA_CONNECTOR_LOCK_DURATION'));
define('RMQ_HOST', getenv('RMQ_HOST'));
define('RMQ_PORT', getenv('RMQ_PORT'));
define('RMQ_VHOST', getenv('RMQ_VHOST'));
define('RMQ_USER', getenv('RMQ_USER'));
define('RMQ_PASS', getenv('RMQ_PASS'));
define('RMQ_QUEUE_IN', getenv('RMQ_QUEUE_IN'));
define('RMQ_QUEUE_OUT', getenv('RMQ_QUEUE_OUT'));
define('RMQ_QUEUE_ERR', getenv('RMQ_QUEUE_ERR'));
