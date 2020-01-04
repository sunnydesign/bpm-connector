# Changelog

Connector BPM-to-RabbitMQ-to-BPM for Camunda BPM on PHP.
Using for transmitting variables from External Tasks to another external workers over AMQP and transmitting results from external workers to External Tasks.


## [Unreleased]

### Planned
- Nothing

## [1.2] - 2020-01-05

### Added
- Added error output when task not completed just as api response code not `204`

## [1.1] - 2019-12-27

### Changed
- Added forwarding safe process variables in to `data.parameters`
- Added cleaning process variables from the `data.parameters` in connector-out

## [1.0] - 2019-12-24

### Changed
- Transfer Logger in composer satis repository
- Changed `.dockerignore`

## [0.4] - 2019-12-20

### Changed
- Detailed logging
- All ticks timeout moved to config
- Format transit messages from Rabbit MQ changed from `String` to `Json`

## [0.3] - 2019-12-17

### Added
- Set non-blocking mode of receiving messages for Rabbit MQ connection
- Set QoS parameter for channel: one message per one loop for Rabbit MQ connection
- Set manual acknowledge for received message for Rabbit MQ connection
- Remove queue declare for Rabbit MQ connection

## [0.2] - 2019-12-17

### Added

- Added basic auth for API Camunda BPM in config
- Added validate message headers in connector-out 
- Added validate Camunda external params in connector-in

## [0.1] - 2019-12-13

### Added

- First worked version on connector
- Docker environment
- Worker for test connector
- README and CHANGELOG

[unreleased]: https://gitlab.com/quancy-core/bpm-connector/-/tags/1.2
[1.2]: https://gitlab.com/quancy-core/bpm-connector/-/tags/1.2
[1.1]: https://gitlab.com/quancy-core/bpm-connector/-/tags/1.1
[1.0]: https://gitlab.com/quancy-core/bpm-connector/-/tags/1.0
[0.4]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.4
[0.3]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.3
[0.2]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.2
[0.1]: https://gitlab.com/quancy-core/bpm-connector/-/tags/v0.1
