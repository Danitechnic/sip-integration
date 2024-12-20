<?php

/*******************************************************************************

 *
 *  AlterVision CPA platform
 *  Created by AlterVision - www.altercpa.pro
 *  Copyright (c) 2013-2024 Anton Reznichenko
 *

 *
 *  File: 			core / autocall / call.php
 *  Description:	SIP call
 *  Author:			Anton 'AlterVision' Reznichenko - altervision13@gmail.com
 *

*******************************************************************************/

// SIP magic wrapper
class SIPcall {

	// Configs
	public	$status	= false;	// Current worker status
	public	$stage	= false;	// Current call status
	public	$start	= 0;		// Call start time
	public	$config	= [];		// Call configuration
	public	$port 	= 0;		// Port to bind to
	private $phone	= false;	// Phone client

	// Set the configuration
	public function __construct( $config ) {
		$this->stage = 0;
		$this->config = $config;
		$this->port = $config['bind'];
		$this->start = time();
	}

	// Time to stop
	public function __destruct() {
		$this->stop();
	}

	// Stop the call
	public function stop() {

		// Close the connection
		if ( $this->phone ) {
			$this->phone->stop();
			$this->phone = null;
		}

	}

	// Run the call procedures
	public function run() {

		// Check status for error
		if ( $this->stage == -1 || $this->status == 'free' ) return 'free';
		$len = time() - $this->start;
		if ( $len > 66 ) return 'free';

		// Check the phone
		if ( $this->phone ) {
			if ( $this->phone->work() ) {
				return false;
			} else return $this->check();
		} else return $this->start();

	}

	// Start the call
	private function start() {

		// Prepare phone
		$this->phone = new SIPphone( $this->config['server'], $this->config['bind'] );
		if ( ! $this->phone->active ) return $this->error( 'error' );

		// Set call parameters
		$this->phone->set([
			'host'		=> $this->config['host'],
			'port'		=> $this->config['port'],
			'method'	=> 'INVITE',
			'from'		=> 'sip:'.$this->config['id'].'@'.$this->config['server'],
			'to'		=> $this->config['phone'],
			'uri'		=> $this->config['phone'],
			'user'		=> $this->config['id'],
			'pass'		=> $this->config['pass'],
		]);
		$this->phone->header( 'Subject: click2call' );
		$this->phone->debug = $this->config['debug'];
//		$this->phone->debug = true;

		// Start the call
		$this->phone->send();
		$this->stage = 1;
		return false;

	}

	// Processing
	private function check() {

		// Choose current stage
		switch ( $this->stage ) {

			// Ringing finished
			case 1:
			$code = $this->phone->rcode;
			if ( $code != 200 ) {

				// Check specific Asterisc reason
				$ahc = (int)  $this->phone->rheader['x-asterisk-hangupcausecode'];
				if ( $ahc ) {

					// Asterisk reasons list
					$ah2s = [
						1	=> 404, // unallocated number 404 Not Found
						2	=> 404, // no route to network 404 Not found
						3	=> 404, // no route to destination 404 Not found
						17	=> 486, // user busy 486 Busy here
						18	=> 408, // no user responding 408 Request Timeout
						19	=> 480, // no answer from the user 480 Temporarily unavailable
						20	=> 480, // subscriber absent 480 Temporarily unavailable
						21	=> 403, // call rejected 403 Forbidden (+)
						22	=> 410, // number changed (w/o diagnostic) 410 Gone
						22	=> 301, // number changed (w/ diagnostic) 301 Moved Permanently
						23	=> 410, // redirection to new destination 410 Gone
						26	=> 404, // non-selected user clearing 404 Not Found (=)
						27	=> 502, // destination out of order 502 Bad Gateway
						28	=> 484, // address incomplete 484 Address incomplete
						29	=> 501, // facility rejected 501 Not implemented
						31	=> 480, // normal unspecified 480 Temporarily unavailable
						34	=> 503, //  no circuit available 503 Service unavailable
						38	=> 503, //  network out of order 503 Service unavailable
						41	=> 503, //  temporary failure 503 Service unavailable
						42	=> 503, //  switching equipment congestion 503 Service unavailable
						47	=> 503, //  resource unavailable 503 Service unavailable
						55	=> 403, //  incoming calls barred within CUG 403 Forbidden
						57	=> 403, //  bearer capability not authorized 403 Forbidden
						58	=> 503, //  bearer capability not presently 503 Service unavailable
						65	=> 488, //  bearer capability not implemented 488 Not Acceptable Here
						70	=> 488, //  only restricted digital avail 488 Not Acceptable Here
						79	=> 501, //  service or option not implemented 501 Not implemented
						87	=> 403, //  user not member of CUG 403 Forbidden
						88	=> 480, //  incompatible destination 503 Service unavailable
						102	=> 504, //  recovery of timer expiry 504 Gateway timeout
						111	=> 500, //  protocol error 500 Server internal error
						127	=> 500, //  interworking unspecified 500 Server internal error
					];

					// Change the code to the reason
					if ( $ah2s[$ahc] ) $code = $ah2s[$ahc];

				}

				// Check status to find the error
				switch ( $code ) {

					// Call is busy
					case 486: case 487: case 491: case 600:
					$this->drop();
					return $this->error( 'busy' );

					// Call is away
					case 503: case 504: case 700:
					$this->drop();
					return $this->error( 'away' );

					// User is gone
					case 408: case 410: case 480: case 603:
					$this->drop();
					return $this->error( 'gone' );

					// Number is wrong
					case 403: case 404: case 484: case 485: case 604:
					$this->drop();
					return $this->error( 'bad' );

					// Technical error
					default:
					$this->drop();
					return $this->error( 'error' );

				}

			} else return 'refer'; // Ok

			// Starting the refer
			case 2:
			if ( ! $this->phone->active ) return $this->error( 'error' );
			if ( ! $this->phone->rcode ) return false;
			if ( $this->phone->rcode == 202 ) {
				$this->stage = 3;
				return $this->renotify();
			} else {
				$this->stage = 4;
				return 'norefer';
			}

			// Refer in progress
			case 3:
			if ( ! $this->phone->active ) return $this->error( 'error' );
			return $this->progress();

			// Refer finished
			case 4:
			#$this->drop();
			$this->stage = 5;
			return false;

			// Call is finished
			case 5:
			return 'free';

		}

	}

	// Drop the call
	public function drop() {

		// Check the phone
		if ( $this->phone ) {

			// Drop the call
			if ( $this->phone->dialog ) {
				$this->phone->set([ 'method' => 'bye' ]);
				$this->phone->send();
			}

			// Set the configuration
			$this->stage = 5;
			return true;

		} else return $this->error();

	}

	// Refer the call to specified number
	public function refer( $to ) {

		// Check the phone
		if ( $this->phone ) {

			// Refer the call
			$this->phone->set([ 'method' => 'refer', 'refer' => $to ]);
			$this->phone->send();

			// Set the configuration
			$this->stage = 2;
			return true;

		} else return $this->error();

	}

	// Refer progress checker
	private function progress() {

		// Get current body code
		$rbody = explode( ' ', $this->phone->rbody );
		$rbodystatus = (int) $rbody[1];

		// Referring is still in progress
		if ( $rbodystatus >= 100 && $rbodystatus < 200 ) return $this->renotify();

		// Referring was failed
		if ( $rbodystatus >= 300 ) {
			$this->stage = 4;
			return 'norefer'; // Error
		}

		// Reffering is 200 and OK
		if ( $rbodystatus == 200 ) {
			$this->stage = 4;
			return false;
		}

		// Check the termination
		if ( strpos( $this->phone->rheader['subscription-state'], 'terminated' ) !== false ) $this->stage = 4;
		elseif ( strpos( $this->phone->rheader['event'], 'refer' ) === false ) return $this->renotify();

		// Refer is now in progress
		$this->stage = 4;
		return false;

	}

	// Continue notify subscription
	private function renotify() {
		$this->phone->listen( 'notify' );
		return false;
	}

	// Set error code and return error status
	private function error( $code ) {
		$this->stage = -1;
		$this->status = 'free';
		return $code ? $code : false;
	}

}