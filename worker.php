<?php

/*******************************************************************************

 *
 *  AlterVision CPA platform
 *  Created by AlterVision - www.altercpa.pro
 *  Copyright (c) 2013-2024 Anton Reznichenko
 *

 *
 *  File: 			core / autocall / worker.php
 *  Description:	Worker launcher
 *  Author:			Anton 'AlterVision' Reznichenko - altervision13@gmail.com
 *

*******************************************************************************/

// Chech the URL
if (!isset( $_SERVER['argv'] )) die('Only launch from console');
if (!isset( $_SERVER['argv'][1] )) die('You forgot the URL');
$url = $_SERVER['argv'][1];
if (!strpos( $url, 'sys/autocall.json' )) die('URL is wrong');

// Find the data directory
define( 'PATH', realpath( __DIR__ . '/../../' ) . '/' );
define( 'PIDFILE', PATH . 'data/work/auto.pid' );

// Load the configuration
$pidfile = sprintf( PIDFILE, $type );
$pid = @file_get_contents( $pidfile );
$file = $_SERVER['PHP_SELF'];

// Checking the script working
if ( is_dir( '/proc/' ) ) {
	if ( $pid && is_dir( "/proc/$pid" ) ) {
		$cmdline = @file_get_contents( "/proc/$pid/cmdline" );
		if ( strpos( $cmdline, $file ) !== false ) exit();
	}
} elseif ( $pid ) {
	exec( 'ps -a '.$pid, $oo );
	$sub = strrpos( $oo[0], 'COMMAND' );
	if ( $sub === false ) $sub = strrpos( $oo[0], 'CMD' );
	$oo = end( $oo );
	$cmdline = trim(substr( $oo, $sub ));
	if ( strpos( $cmdline, $file ) !== false ) exit();
}

// Server is running, save the process ID
$pid = getmypid();
file_put_contents( $pidfile, $pid );

// Load all the worker libraries
require_once __DIR__ . '/server.php';
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/call.php';
require_once __DIR__ . '/phone.php';

// Kill signal handler
function settimetodie( $signo, $siginfo = false ) {
	if (!defined( 'TIMETODIE' )) define( 'TIMETODIE', true );
}

// Set handler for kill signals
if (function_exists( 'pcntl_signal' )) {
	pcntl_async_signals(true);
	pcntl_signal( SIGTERM, 'settimetodie' );
	pcntl_signal( SIGHUP, 'settimetodie' );
	pcntl_signal( SIGINT, 'settimetodie' );
}

// Start the server process
set_time_limit( 10000 );
$server = new CallServer( $url, PATH . 'data/work/cron-auto.txt', $pid, $pidfile );
$server->run();
unset( $server );