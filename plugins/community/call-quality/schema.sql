-- One row per call leg (one SIP Call-ID: the trunk leg and the phone leg of a
-- call are two rows), built from the RTCP reports on pbx_signaling_events.
--
-- "in" is what this server received (the reports Asterisk sends describe it),
-- "out" is what the other side received (the reports it sends back). So a
-- trunk with loss "in" loses audio on the way from the carrier, with loss
-- "out" on the way to it.
--
-- Loss is in percent (RFC 3550's fraction_lost / 256). Jitter stays in RTP
-- timestamp units, as Asterisk reports it: the sample rate is not in the
-- report (8 units = 1 ms only for 8 kHz codecs such as G.711).

CREATE TABLE IF NOT EXISTS plg_call_quality_legs (
    call_id VARCHAR(128) NOT NULL,
    first_ts DATETIME NOT NULL,
    last_ts DATETIME NOT NULL,
    leg_type VARCHAR(10) NOT NULL DEFAULT '',
    leg_name VARCHAR(64) NOT NULL DEFAULT '',
    peer VARCHAR(64) NOT NULL DEFAULT '',
    reports_in INT UNSIGNED NOT NULL DEFAULT 0,
    reports_out INT UNSIGNED NOT NULL DEFAULT 0,
    bad_in INT UNSIGNED NOT NULL DEFAULT 0,
    bad_out INT UNSIGNED NOT NULL DEFAULT 0,
    loss_in_max DECIMAL(5,2) NOT NULL DEFAULT 0,
    loss_out_max DECIMAL(5,2) NOT NULL DEFAULT 0,
    loss_in_sum DECIMAL(10,2) NOT NULL DEFAULT 0,
    loss_out_sum DECIMAL(10,2) NOT NULL DEFAULT 0,
    jitter_in_max INT UNSIGNED NOT NULL DEFAULT 0,
    jitter_out_max INT UNSIGNED NOT NULL DEFAULT 0,
    level VARCHAR(5) NOT NULL DEFAULT 'good',
    PRIMARY KEY (call_id),
    KEY plg_call_quality_legs_last_ts (last_ts),
    KEY plg_call_quality_legs_leg (leg_type, leg_name, last_ts)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
