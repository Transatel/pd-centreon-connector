# pd-centreon-connector

Propagate ACKs from [PagerDuty](https://pagerduty.com) to [Centreon](https://www.centreon.com/).

This is a reimplementation of [pd-nag-connector](https://github.com/jeffwalter/pd-nag-connector) in PHP, targeting Centreon, with additional features.

## Features

### Support for multi-poller architecure

By using [Transatel/api-centreon-notification-helper](https://github.com/Transatel/api-centreon-notification-helper), we can send acks / unacks to any service even in a multi-poller architecure.

### Multi-API version support

The code is compatible with both [PagerDuty Webhooks API v1](https://v2.developer.pagerduty.com/docs/webhooks-v1-overview) and [v2](https://v2.developer.pagerduty.com/docs/webhooks-v2-overview).

### User and channel in ACK message

The username as well as the channel (`website` or `mobile`) will be extracted from the webhook request and used to compose the ack message in Centreon:

	Acknowledged by <USER> (via PagerDuty - <CHANNEL>)

### Loop prevention

Before ack'ing / deack'ing, we test the state the service / host to ensure that we don't trigger a loop in case of a bi-directional integration between Centreon and PagerDuty.

## Configuration

The configuration is at the top of the [pagerduty.php](./pagerduty.php) file.

Here is the bare minimum you need to configure:

| Key                     | Description                                                                                                               |
| --                      | --                                                                                                                        |
| NOTIF\_HELPER\_API\_URL | URL to access [Transatel/api-centreon-notification-helper](https://github.com/Transatel/api-centreon-notification-helper) |

Additionally, you can play with the following:

| Key                    | Description                                                                                      |
| --                     | --                                                                                               |
| UNACK\_ON\_RESOLVE     | If `true` unacks when receiving an `incident.resolve`                                            |
| IS\_STICKY             | Does ack stay on status change. Either `NAGIOS_STICKY_YES` or `NAGIOS_STICKY_NO`                 |
| DO\_NOTIFY             | Do we notify on ack. Either `NAGIOS_NOTIFY_YES` or `NAGIOS_NOTIFY_NO`                            |
| IS\_PERSISTENT         | Is ack persistent bewteen poller reboots. Either `NAGIOS_PERISTENT_YES` or `NAGIOS_PERISTENT_NO` |
| DEFAULT\_ACK\_USERNAME | Fallback username to put in ACK message                                                          |
