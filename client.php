<?php

/*******************************************************************************

 *
 *  AlterVision CPA platform
 *  Created by AlterVision - www.altercpa.pro
 *  Copyright (c) 2013-2024 Anton Reznichenko
 *

 *
 *  File: 			core / autocall / client.php
 *  Description:	Call processing client
 *  Author:			Anton 'AlterVision' Reznichenko - altervision13@gmail.com
 *

*******************************************************************************/

// Call processing client
class CallClient {

	// Configs
	private $server;		// Server internal configuration
	private $config = [];	// Client internal configuration
	private $call = [];		// Processed calls
	private $active = 0;	// Last activity update time
	private $group = [];	// Numbers to pick from the group
	private $calls = [];	// Active calls by the group
	private $time = 0;		// Internal client timer

	// Start the client
	public function __construct( $server, $config ) {
		$this->time = time();
		$this->server = $server;
		$this->reload( $config );
	}

	// Stop the client
	public function __destruct() {
		$this->stop();
	}

	// Reload client configuration
	public function reload( $config ) {
		$this->config = $config;
	}

	// Stop all the processes
	public function stop() {
		$c = array_keys( $this->call );
		foreach ( $c as $i ) $this->call[$i]->stop();
		foreach ( $c as $i ) unset( $this->call[$i] );
	}

	// Run all the processes
	public function run() {
		$this->time = time();
		$boring = $this->calls();
		if ( $this->active < $this->time ) $boring = $this->active();
		return $boring;
	}

	// Run parts: active
	private function active() {

		// Set next call time
		$this->active = $this->time + rand( 9, 13 );

		// Get current activity table
		$active = $this->api( 'active' );
		if ( ! $active ) return 0;
		if ( $active['error'] ) return 0;

		// Get the list of phones by team
		$this->group = [];
		foreach ( $active as $a ) if ( $a['wait'] ) $this->group[ $a['team'] ][ $a['user'] ] = $a['phone'];

		// Walk through the groups for processing
		foreach ( $this->config['group'] as $gc ) {

			// Make group free numbers list
			$gi = (int) $gc['team'];
			$gwl = $this->group[$gi] ? count( $this->group[$gi] ) : 0;

			// Make all the available calls
			$ac = ( $gc['coef'] ) ? round( $gwl * $gc['coef'] ) : $gwl;
			$ac -= $this->calls[$gi];
			if ( $ac > 0 ) for ( $x = 0; $x < $ac; $x++ ) {
				$o = $this->api( 'pick', [ 'oid' => -1, 'mark' => 120, 'team' => $gi ] );
				if ( ! $o ) continue;
				if ( $o['error'] ) continue;
				$this->callto( $gi, $o );
			}

		}

		// It's never boring here
		return 0;

	}

	// Run parts: calls
	private function calls() {

		// Run the calls
		$ci = array_keys( $this->call );
		$boring = count( $ci ) ? 1 : 2;
		foreach ( $ci as $i ) {

			// Run the call and get the result
			$gi = $this->call[$i]->config['group'];
			$oi = $this->call[$i]->config['order'];
			$result = $this->call[$i]->run();
			switch ( $result ) {

				// Refer the call
				case 'refer':
				if ( $this->group[$gi] ) {
					$u = array_rand( $this->group[$gi] );
					$p = $this->refurl( $this->group[$gi][$u] );
					$this->call[$i]->refer( $p );
					$this->refer( $oi, $u );
					unset( $this->group[$gi][$u] );
				} else {
					$this->call[$i]->drop();
					$recall = $this->config['group'][$gi]['error'];
					if ( ! $recall ) $recall = 10;
					$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 5, 'unmark' => 1 ] );
				}
				break;

				// No free operators
				case 'norefer':
				$recall = $this->config['group'][$gi]['error'];
				if ( ! $recall ) $recall = 10;
				$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 7, 'unmark' => 1 ] );
				break;

				// Number is busy now
				case 'busy':
				$recall = $this->config['group'][$gi]['busy'];
				if ( ! $recall ) $recall = 5;
				$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 1, 'unmark' => 1 ] );
				break;

				// Number is away from phone
				case 'away':
				$recall = $this->config['group'][$gi]['away'];
				if ( ! $recall ) $recall = 90;
				$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 2, 'unmark' => 1 ] );
				break;

				// Number has gone far away
				case 'gone':
				$recall = $this->config['group'][$gi]['gone'];
				if ( ! $recall ) $recall = 90;
				$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 3, 'unmark' => 1 ] );
				break;

				// Error while calling
				case 'error':
				$recall = $this->config['group'][$gi]['error'];
				if ( ! $recall ) $recall = 10;
				$this->order( $oi, [ 'status' => 3, 'recall' => $recall, 'problem' => 6, 'unmark' => 1 ] );
				break;

				// Number is bad
				case 'bad':
				$this->order( $oi, [ 'status' => 5, 'reason' => 1 ] );
				break;

				// Destroy this call
				case 'free':
				$this->server->free( $this->call[$i]->port );
				$this->calls[$gi] -= 1;
				$this->call[$i]->stop();
				unset( $this->call[$i] );
				break;

			}

		}

		return $boring;

	}

	// Create new call
	private function callto( $gi, $o ) {

		// Get free port
		$port = $this->server->port();
		if ( ! $port ) return false;

		// Make new call
		$this->call[] = new SIPcall([
			'server'	=> $this->server->host,
			'bind'		=> $port,
			'host'		=> $this->config['sip-host'],
			'port'		=> $this->config['sip-port'],
			'id'		=> $this->config['sip-id'],
			'pass'		=> $this->config['sip-pass'],
			'group'		=> $gi,
			'order'		=> $o['id'],
			'phone'		=> $this->sipurl( $o['phone'] ),
			'number'	=> $o['phone'],
		]);

		// Add call to group calls
		if (!isset( $this->calls[$gi] )) $this->calls[$gi] = 0;
		$this->calls[$gi] += 1;
		return true;

	}

	// Create the SIP URL to call
	private function sipurl( $phone ) {
		$url = $this->config['format'] ? $this->config['format'] : 'sip:+{number}@{host}:{port}';
		$url = str_replace( '{number}', $phone, $url );
		$url = str_replace( '{server}', $this->server->host, $url );
		$url = str_replace( '{host}', $this->config['sip-host'], $url );
		$url = str_replace( '{port}', $this->config['sip-port'], $url );
		$url = str_replace( '{id}', $this->config['sip-id'], $url );
		$url = str_replace( '{pass}', $this->config['sip-pass'], $url );
		return $url;
	}

	// Create the referer URL to call
	private function refurl( $phone ) {
		$url = $this->config['refer'] ? $this->config['refer'] : 'sip:{number}@{host}';
		$url = str_replace( '{number}', $phone, $url );
		$url = str_replace( '{server}', $this->server->host, $url );
		$url = str_replace( '{host}', $this->config['sip-host'], $url );
		$url = str_replace( '{port}', $this->config['sip-port'], $url );
		$url = str_replace( '{id}', $this->config['sip-id'], $url );
		$url = str_replace( '{pass}', $this->config['sip-pass'], $url );
		return $url;
	}

	// Update the order
	private function order( $order, $data ) {
		if ( $data['recall'] ) $data['recall'] = $this->time + ( $data['recall'] * 60 );
		$this->api( 'edit', [ 'oid' => $order ], $data );
	}

	// Refer the order
	private function refer( $order, $user ) {
		$this->api( 'refer', [ 'oid' => $order, 'uid' => $user ] );
	}

	// Fast API CURL
	private function api( $func, $param = false, $post = false ) {

		// Make the URL
		$token = $this->config['api-id'] . '-' . $this->config['api-key'];
		$url = $this->server->api . 'api/comp/' . $func . '.jsonpp?id=' . $token;
		if ( $param ) $url .= '&' . http_build_query( $param );

		// Main options
		$curl = curl_init( $url );
		curl_setopt( $curl, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 6.1; WOW64; rv:66.0) Gecko/20100101 Firefox/66.0' );
		curl_setopt( $curl, CURLOPT_TIMEOUT, 1 ); // Very fast
		curl_setopt( $curl, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $curl, CURLOPT_FOLLOWLOCATION, 1 );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYPEER, 0 );
		curl_setopt( $curl, CURLOPT_SSL_VERIFYHOST, 0 );
		curl_setopt( $curl, CURLOPT_FAILONERROR, false );

		// Post block
		if ( $post ) {
			curl_setopt( $curl, CURLOPT_POST, 1 );
			curl_setopt( $curl, CURLOPT_POSTFIELDS, $post );
		}

		// Processing
		$result = curl_exec( $curl );
		curl_close( $curl );
		return $result ? json_decode( $result, true ) : false;

	}

}