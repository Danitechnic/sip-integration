<?php

/*******************************************************************************

 *
 *  AlterVision CPA platform
 *  Created by AlterVision - www.altercpa.pro
 *  Copyright (c) 2013-2024 Anton Reznichenko
 *

 *
 *  File: 			core / autocall / server.php
 *  Description:	Call processing server
 *  Author:			Anton 'AlterVision' Reznichenko - altervision13@gmail.com
 *

*******************************************************************************/

// Call processor
class CallServer {

	// Internal configuration
	private $url = false;		// API request URL
	private $client = [];		// Processing clients
	private $config = [];		// Server configuration
	private $reload = 0;		// Last config reload time
	private $port = [];			// Available ports list
	private	$log = false;		// Process log file
	private	$pid = false;		// Process ID
	private	$pif = false;		// Process ID file
	private	$start = 0;			// Start time

	// Public configuration
	public	$api = false;		// Base URL for API requests
	public	$host = false;		// Server host IP

	// Prepare the server
	public function __construct( $url, $log, $pid, $pif ) {
		$this->url = $url;
		$this->log = $log;
		$this->pid = (int) $pid;
		$this->pif = $pif;
		$this->start = time();
	}

	// Stop the server
	public function __destruct() {
		foreach ( $this->client as $c ) $c->stop();
	}

	// Run the server
	public function run() {
		while ( true ) {

			// Main cycle
			if (defined( 'TIMETODIE' )) break;
			$work = $this->clients();
			if ( $work == 1 ) usleep( 100 );
			if ( $work == 2 ) sleep( 10 );

			// Check the PID
			if ( $this->pid && $this->pif && $work == 2 ) {
				$pid = (int) @file_get_contents( $this->pif );
				if ( $pid != $this->pid ) break;
			}

		}
	}

	// Working with clients
	private function clients() {

		// Hope it wont be boring
		$boring = 2;
		$ttr = time() - 60;
		if ( $this->reload < $ttr ) $boring = $this->reload();

		// Process the clients
		foreach ( $this->client as $c ) $boring = min( $boring, $c->run() );
		return $boring;

	}

	// Reload the configuration
	private function reload() {

		// Save process info
		$tm = time();
		if ( $this->log ) {
			$tt = $tm - $this->start;
			$mm = function_exists('memory_get_peak_usage') ? memory_get_peak_usage() : 0;
			$ii = [ 'timer' => $tt, 'memory' => $mm ];
			file_put_contents( $this->log, json_encode($ii) );
		}

		// Load the configuration from server
		$curl = curl_init( $this->url );
		curl_setopt( $curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:66.0) Gecko/20100101 Firefox/66.0' );
		curl_setopt( $curl, CURLOPT_TIMEOUT, 1 ); // Very fast
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $curl, CURLOPT_FAILONERROR, false );
		$cfg = json_decode( curl_exec( $curl ), true );
		curl_close( $curl );

		// Server configuration
		if (isset( $cfg['config'] )) {
			$this->config = $cfg['config'];
			$this->api = $cfg['config']['url'];
			$this->host = $cfg['config']['host'];
			$this->reload = $tm;
		} else return 0;

		// Client configurations
		if (isset( $cfg['client'] )) {

			// Check running clients
			$cl = array_keys( $this->client );
			foreach ( $cl as $c ) {
				if ( $cfg['client'][$c] ) {
					$this->client[$c]->reload( $cfg['client'][$c] );
					unset( $cfg['client'][$c] );
				} else {
					$this->client[$c]->stop();
					unset( $this->client[$c] );
				}
			}

			// Make new clients
			if ( $cfg['client'] ) foreach ( $cfg['client'] as $c => $t ) $this->client[$c] = new CallClient( $this, $t );

		}

		return 1;

	}

	// Get a free port
	public function port() {

		// Walk through ports to find a free one
		for ( $i = $this->config['min']; $i <= $this->config['max']; $i++ ) {
			if ( ! $this->port[$i] ) {
				$this->port[$i] = true;
				return $i;
			}
		}

		// No free port found, try later
		return false;

	}

	// Free the gotten port
	public function free( $i ) {
		unset( $this->port[$i] );
	}

}