<?php

/*******************************************************************************

 *
 *  AlterVision CPA platform
 *  Created by AlterVision - www.altercpa.pro
 *  Copyright (c) 2013-2024 Anton Reznichenko
 *

 *
 *  File: 			core / autocall / phone.php
 *  Description:	SIP phone implementation
 *  Author:			Anton 'AlterVision' Reznichenko - altervision13@gmail.com
 *

 *
 *	Based on:		PHP SIP UAC class
 *	Repository:		https://github.com/level7systems/php-sip
 *	Author:			Chris Maciejewski <chris@level7systems.co.uk>
 *

*******************************************************************************/

// The SIP phone
class SIPphone {

	// Internal configuration
	public	$active	= 0;	// Is phone active
	public	$dialog	= 0;	// Is dialog established
	public	$debug	= 0;	// Debug mode enabled
	private	$mode 	= 0;	// Work mode: 0 - wait, 1 - send, 2 - listen
	private	$step	= 0;	// Current step of mode
	private $time	= 0;	// Last request time

	// Connection
	private $socket;		// Working socket
	public	$server;		// IP address to bind
	private $bind = 0;		// Port to bind to
	private $message = '';	// Current message
	private	$buffer	= '';	// Message buffer
	private $listen = [];	// Methods to listen to
	private $host;			// Host to send request
	private $port = 5060;	// Port to send request
	private $auth;			// Authentication string
	private $username;		// Auth username
	private $password;		// Auth password
	private $ua = 'AlterCPA AutoCall';

	// Call
	private $done = [];		// Processed outgoing sequences
	private $method;		// Current request method
	private $via;			// Via
	private $routes = [];	// Routes
	private $cseq = 20;		// CSeq
	public	$callid;		// Call-ID
	private $contact;		// Contact
	private $uri;			// SIP URI
	private $to;			// To
	private $to_tag;		// To tag
	private $from;			// From
	private $from_user;		// User part of from
	private $from_tag;		// From tag
	private $ct;			// Content type
	private $headers = [];	// Request additional headers
	private $body;			// Request body

	// Result
	private $rdone 	= [];	// Processed incoming sequences
	public	$result = 0;	// Have we got any result to work with
	public	$rtype	= 0;	// Result type: 0 - reply, 1 - request
	public	$rgroup	= 0;	// Code group
	public	$rcode	= 0;	// Code
	public	$rheader = [];	// All result headers
	public	$rmethod = '';	// Method
	private $rcseq 	= 0;	// CSeq number
	private	$rcsm 	= '';	// CSeq method
	private	$rvia 	= [];	// Via
	private $rr 	= [];	// Record route
	private	$rcontact;		// Contact
	private	$rfrom;			// From
	private	$rfrom_tag;		// From tag
	private	$rto;			// To
	private	$rto_tag;		// To tag
	public	$rbody;			// Body

	// Creating the socket for the phone
	public function __construct( $ip, $port ) {

		// Preparing
		$this->active = false;
		$this->server = $ip;
		$this->bind = $port;
		$this->callid = md5(uniqid());

		// Create socket
		$this->socket = socket_create( AF_INET, SOCK_DGRAM, SOL_UDP );
		if ( $this->socket ) {
			if ( socket_bind( $this->socket, $ip, $port ) ) {
				$this->active = true;
				socket_set_option( $this->socket, SOL_SOCKET, SO_SNDTIMEO, [ 'sec' => 1, 'usec' => 0 ] );
				socket_set_option( $this->socket, SOL_SOCKET, SO_RCVTIMEO, [ 'sec' => 0, 'usec' => 10 ] );
			} else socket_close( $this->socket );
		}

	}

	// Call the stopper
	public function __destruct() {
		$this->stop();
	}

	// Stop all the functionality
	public function stop() {

		// Close the dialog if established
		if ( $this->dialog ) {
			$this->set([ 'method' => 'bye' ]);
			$this->send();
			$this->dialog = false;
		}

		// Close socket if required
		if ( $this->socket ) {
			socket_close( $this->socket );
			$this->socket = false;
		}

	}

	// Set basic parameters
	public function set( $data ) {

		// Method
		if (isset( $data['method'] )) {

			// Setting the method field
			$method = strtoupper( $data['method'] );
			$this->method = $method;

			// Additional sets for methods
			switch ( $method ) {

				// Body for the invite
				case 'INVITE':
				$body = "v=0\r\n";
				$body.= "o=click2dial 0 0 IN IP4 ".$this->server."\r\n";
				$body.= "s=click2dial call\r\n";
				$body.= "c=IN IP4 ".$this->server."\r\n";
				$body.= "t=0 0\r\n";
				$body.= "m=audio 8000 RTP/AVP 0 8 18 3 4 97 98\r\n";
				$body.= "a=rtpmap:0 PCMU/8000\r\n";
				$body.= "a=rtpmap:18 G729/8000\r\n";
				$body.= "a=rtpmap:97 ilbc/8000\r\n";
				$body.= "a=rtpmap:98 speex/8000\r\n";
				$this->body = $body;
				$this->ct = null;
				$this->dialog = true;
				break;

				// No body for refer
				case 'REFER':
				$this->body = '';
				break;

				// No body and content type for cancel
				case 'BYE':
				$this->dialog = false; // no break here!
				case 'CANCEL':
				$this->body = '';
				$this->ct = null;
				break;

			}

		}

		// URI
		if (isset( $data['uri'] )) {

			// Basic set
			$this->uri = $data['uri'];
			if ( ! $this->to ) $this->to = '<'.$data['uri'].'>';

			// Host and port
			if (!( $this->host || $data['host'] )) {

				// Clean the URI for parsing
				$uri = $data['uri'];
				$uri = ( $t_pos = strpos( $uri, ';' ) ) ? substr( $uri, 0, $t_pos ) : $uri;
				$uri = str_replace( 'sip:', 'sip://', $uri );

				// Parse URI and set host:port
				$uu = @parse_url( $uri );
				if ( $uu ) {
					$this->host = $uu['host'];
					if ( $uu['port'] ) $this->port = $uu['port'];
				}

			}

		}

		// From
		if (isset( $data['from'] )) {
			if (preg_match('/<.*>$/',$data['from'])) {
				$this->from = $data['from'];
			} else $this->from = '<'.$data['from'].'>';
			$this->from_user = preg_match( '/sip:(.*)@/i', $this->from, $ms ) ? $ms[1] : '';
		}

		// To
		if (isset( $data['to'] )) {
			if (preg_match('/<.*>$/',$data['to'])) {
				$this->to = $data['to'];
			} else $this->to = '<'.$data['to'].'>';
		}

		// Refer
		if (isset( $data['refer'] )) {
			$this->headers[] = 'Refer-To: '.$data['refer'];
			$this->headers[] = 'Referred-By: '.$this->from;
			$this->headers[] = 'Supported: replaces';
#			$this->headers[] = 'Supported: gruu, replaces, tdialog';
#			$this->headers[] = 'Require: tdialog';
#			$this->headers[] = 'Target-Dialog: '.$this->callid.';local-tag='.$this->from_tag.';remote-tag='.$this->rto_tag;
#			$this->headers[] = 'Target-Dialog: '.$this->callid.';local-tag='.$this->to_tag.';remote-tag='.$this->from_tag;
		}

		// Misc
		if (isset( $data['body'] )) $this->ua = $data['body'];
		if (isset( $data['host'] )) $this->host = $data['host'];
		if (isset( $data['port'] )) $this->port = $data['port'];
		if (isset( $data['user'] )) $this->username = $data['user'];
		if (isset( $data['pass'] )) $this->password = $data['pass'];
		if (isset( $data['contact'] )) $this->contact = $data['contact'];
		if (isset( $data['ua'] )) $this->ua = $data['ua'];
		if (isset( $data['ct'] )) $this->ua = $data['ct'];

	}

	// Add header to request
	public function header( $header ) {
		$this->headers[] = $header;
	}

	// Make the working process
	public function work() {

		// Try to load anything from socket
		if ( $this->mode ) {

			// Check timer
			if ( $this->timer ) {
				$tm = time() - 60;
				if ( $this->timer < $tm ) {

					// Close the dialog first
					$this->set([ 'method' => 'bye' ]);
					$this->send();

					// Set special internal status 700
					$this->rgroup = 7;
					$this->rcode = 700;
					return $this->error( 'Socket timeout' );

				}
			}

			// Try to load anythigh from socket
			$from = ''; $port = 0; $msg = ''; // Hack for reference passing
			if (socket_recvfrom( $this->socket, $msg, 2048, MSG_DONTWAIT, $from, $port )) if ( $msg ) {

				// Add message part to the buffer
				$this->buffer .= $msg;
				if ( $this->debug ) $this->log( $msg, 1 );

				// Check the buffer for messages
				if ( strpos( $this->buffer, "\r\n\r\n" ) !== false ) {

					// Set the message from buffer
					$x = explode( "\r\n\r\n", $this->buffer, 2 );
					$this->message = $x[0];

					// Check for the content length
					if (preg_match( '/^Content-length: (.*)/im', $this->message, $ms )) {

						// Check the buffer size to fit content length
						$len = (int) $ms[1];
						$bln = strlen( $x[1] );

						// Only if it fits, make full message and parse it
						if ( $len <= $bln ) {
							$z = explode( "\r\n\r\n", $x[1], 2 );
							$this->message .= "\r\n\r\n" . $z[0];
							$this->buffer = $z[1];
							$this->parse();
						}

					} else {

						// Set new buffer and parse reply
						$this->buffer = $x[1];
						$this->parse();

					}

				}

			}

		}

		// Work in sending mode
		if ( $this->result && $this->mode == 1 ) {

			// First step - wait for auth
			if ( $this->step == 0 ) {

				// Proxy auth
				if ( $this->rcode == 407 ) {
					$this->cseq++;
					if ( ! $this->auth() ) return $this->error( 'Authentication failed' );
					$this->makerequest();
				}

				// WWW auth
				if ( $this->rcode == 401 ) {
					$this->cseq++;
					if ( ! $this->wwwauth() ) return $this->error( 'Authentication failed' );
					$this->makerequest();
				}

				if ( $this->method == 'CANCEL' && $this->rcode == 200 ) {
					$this->result = false;
				}

				// Go to the next step
				$this->step += 1;

			} else {

				// Look at method
				if ( $this->method == 'CANCEL' ) {

					// Ask for code two times for cancel
					if ( $this->rgroup != 4 && $this->step < 3 ) {
						$this->result = false;
					} else return $this->sent();

				} else {

					// Ask for code five times for other messages
					if ( $this->rgroup == 1 && $this->step < 5 ) {
						$this->result = false;
					} else return $this->sent();

				}

				// Next step
				$this->step += 1;

			}

		}

		// Work in listening mode
		if ( $this->result && $this->mode == 2 ) {
			if ( $this->rtype && in_array( $this->rmethod, $this->listen ) ) $this->mode = 0;
		}

		// For the cycle
		return $this->mode ? true : false;

	}

	// Send the request
	public function send() {

		// Just make the request and wait for reply later
		$this->makerequest();
		$this->mode = 1;
		$this->step = 0;

	}

	// Request sent successfully
	public function sent() {

		// Null the request info
		$this->headers = [];
		$this->mode = 0;
		$this->cseq += 1;
		return true;

	}

	// Listen to events
	public function listen( $method, $type = 0 ) {

		if (!is_array( $method )) $method = [ $method ];
		$method = array_map( 'strtoupper', $method );
		$this->listen = $method;
		$this->mode = 2;
		$this->step = $type;

	}

	// Simply send the data via socket
	private function sendit( $data, $silent = false ) {

		// Make text if array was send
		if (is_array( $data )) $data = implode( "\r\n", $data ) . "\r\n\r\n";

		// Log sending data if needed
		if ( $this->debug ) $this->log( $data, 0 );

		// Put it into socket
		if ( @socket_sendto( $this->socket, $data, strlen( $data ), 0, $this->host, $this->port )) {
			if ( ! $silent ) {
				$this->result = false;
				$this->timer = time();
			}
			return true;
		} else {
			if ( $this->debug ) $this->error( socket_strerror(socket_last_error($this->socket)) );
			return false;
		}

	}

	// Parse the message
	private function parse() {

		// Check body
		if ( strpos( $this->message, "\r\n\r\n" ) !== false ) {
			$tmp = explode( "\r\n\r\n", $this->message, 2 );
			$this->rbody = trim( $tmp[1] );
			$msg = trim( $tmp[0] );
		} else {
			$this->rbody = false;
			$msg = trim( $this->message );
		}

		// Check type
		if (preg_match( '/^SIP\/2\.0 ([0-9]{3})/', $msg, $ms )) {

			// Reply
			$this->rtype = 0;
			$this->rcode = (int) $ms[1];
			$this->rgroup = (int) substr( (string) $ms[1], 0, 1 );
			$this->rmethod = false;

		} else {

			// Request
			$this->rtype = 1;
			$this->rcode = $this->rgroup = 0;
			$this->rmethod = strtoupper(trim(substr( $msg, 0, strpos( $msg, ' ' ) )));

		}

		// Parse all the headers
		$this->rheader = [];
		$htp = explode( "\n", $msg );
		unset( $htp[0] );
		foreach ( $htp as $h ) {

			// Get header name and value
			$h = trim( $h );
			$hh = explode( ':', $h, 2 );
			$hn = trim(strtolower( $hh[0] ));
			$hv = trim( $hh[1] );

			// Add header to array or single entry
			if (isset( $this->rheader[$hn] )) {
				if (is_array( $this->rheader[$hn] )) {
					$this->rheader[$hn][] = $hv;
				} else $this->rheader[$hn] = [ $this->rheader[$hn], $hv ];
			} else $this->rheader[$hn] = $hv;

		}

		// Via
		$this->rvia = [];
		if (preg_match_all( '/^Via: (.*)$/im', $msg, $ms )) foreach ( $ms[1] as $m ) $this->rvia[] = trim( $m );

		// Record route
		$this->rr = [];
		if (preg_match_all( '/^Record-Route: (.*)$/im', $msg, $ms )) foreach ( $ms[1] as $m ) {
			$m = trim( $m );
			$this->rr[] = $m;
			$mr = explode( ',', $m );
			foreach ( $mr as $mm ) {
				$mm = trim( $mm );
				if (!in_array( $mm, $this->routes )) $this->routes[] = $mm;
			}
		}

		// Contact
		if (preg_match( '/^Contact:.*<(.*)>/im', $msg, $ms )) {
			$this->rcontact = trim( $ms[1] );
			$xc = strpos( $this->rcontact, ';' );
			if ( $xc !== false ) $this->rcontact = substr( $this->rcontact, 0, $xc );
		} else $this->rcontact = false;

		// CSeq number and method
		if (preg_match('/^CSeq: ([0-9]+) (.*)$/im', $msg, $ms )) {
			$this->rcseq = (int) $ms[1];
			$this->rcsm = strtoupper(trim( $ms[2] ));
		} else $this->rcseq = $this->rcsm = false;

		// From
		if (preg_match('/^From: (.*);tag=(.*)$/im', $msg, $ms )) {
			$this->rfrom = trim( $ms[1] );
			$this->rfrom_tag = trim( $ms[2] );
		} elseif (preg_match('/^From: (.*)/im', $msg, $ms )) {
			if (strpos( $ms[1], ';' )) {
				$mm = explode( $ms[1], ';' );
				$this->rfrom = trim( $mm[0] );
				$this->rfrom_tag = substr( trim( $mm[1] ), 4 );
			} else {
				$this->rfrom = $ms[1];
				$this->rfrom_tag = false;
			}
		} else $this->rfrom = $this->rfrom_tag = false;

		// To
		if (preg_match('/^To: (.*);tag=(.*)$/im', $msg, $ms )) {
			$this->rto = trim( $ms[1] );
			$this->rto_tag = trim( $ms[2] );
			if ( $this->rto_tag && $this->rcode == 200 ) $this->to_tag = $this->rto_tag;
#			if ( ! $this->to_tag ) $this->to_tag = $this->rto_tag;
		} elseif (preg_match('/^To: (.*)/im', $msg, $ms )) {
			if (strpos( $ms[1], ';' )) {
				$mm = explode( $ms[1], ';' );
				$this->rto = trim( $mm[0] );
				$this->rto_tag = substr( trim( $mm[1] ), 4 );
			} else {
				$this->rto = $ms[1];
				$this->rto_tag = rand( 10000, 99999 );
			}
		} else $this->rto = $this->rto_tag = false;

		// Automatic answers
		if ( $this->rtype ) {

			// Check the result
			if ( $this->rdone[$this->rcseq] ) {

				// Always auto-reply with 200
				$this->result = false;
				$this->reply( 200, 'OK' );

			} else {

				// Mark the request as processed
				$this->result = true;
				$this->rdone[$this->rcseq] = true;

				// Automatically reply 200 no notify
				if ( $this->rmethod == 'NOTIFY' ) $this->reply( 200, 'OK' );

			}

		} else {

			// Check the result status group
			if ( $this->rgroup > 1 ) {

				// Check the result and mark as processes
				$this->result = $this->done[$this->rcseq] ? false : true;
				$this->done[$this->rcseq] = true;

				// ACK 2XX-6XX - only invites - RFC3261 17.1.2.1
				if ( $this->rcsm == 'INVITE' ) $this->ack();

			} else $this->result = true;

		}

		if ( $this->debug && !$this->result ) $this->log( 'Duplicate "'.$this->rcseq.' '.$this->rcsm.'", ignoring this request', 3 );

	}

	// Send simple response
	public function reply( $code, $text ) {

		$r = [ "SIP/2.0 $code $text" ];
		foreach ( $this->rvia as $v ) $r[] = 'Via: '.$v;
		foreach ( $this->rr as $v ) $r[] = 'Record-Route: '.$v;
		$r[] = 'From: '.$this->rfrom.';tag='.$this->rfrom_tag;
		$r[] = 'To: '.$this->rto.';tag='.$this->rto_tag;
		$r[] = 'Call-ID: '.$this->callid;
		$r[] = 'CSeq: '.$this->rcseq.' '.$this->rcsm;
		$r[] = 'Max-Forwards: 70';
		$r[] = 'User-Agent: ' . $this->ua;
		$r[] = 'Content-Length: 0';

		return $this->sendit( $r, true );

	}

	// Send the ACK message
	private function ack() {

		// Start block
		if ( $this->rcsm == 'INVITE' && $this->rcode == 200 ) {
			$a = [ 'ACK '.$this->rcontact.' SIP/2.0' ];
		} else $a = [ 'ACK '.$this->uri.' SIP/2.0' ];

		// Routing
		$a[] = 'Via: ' . $this->via;
		if ( $this->routes ) $a[] = 'Route: '.implode( ",", array_reverse($this->routes) );

		// From
		if ( ! $this->from_tag ) $this->from_tag = rand( 10000,99999 );
		$a[] = 'From: '.$this->from.';tag='.$this->from_tag;

		// To
		if ( $this->to_tag ) {
		  $a[] = 'To: '.$this->to.';tag='.$this->to_tag;
		} else $a[] = 'To: '.$this->to;

		// All the other stuff
		$a[] = 'Call-ID: '.$this->callid;
		$a[] = 'CSeq: '.$this->rcseq.' ACK';
		if ( $this->rcode == 200 && $this->auth ) $a[] = 'Proxy-Authorization: '.$this->auth;
		$a[] = 'Max-Forwards: 70';
		$a[] = 'User-Agent: '.$this->ua;
		$a[] = 'Content-Length: 0';

		$this->sendit( $a, true );

	}

	// Make the request from settings
	private function makerequest() {

		// Header
		if ($this->rcontact && in_array($this->method,array('BYE','REFER','SUBSCRIBE'))) {
			$u = $this->rcontact;
		} else $u = $this->uri;
		$r = [ $this->method.' '.$u.' SIP/2.0' ];

		// Routing
		if ( $this->method != 'CANCEL' ) $this->via = 'SIP/2.0/UDP '.$this->server.':'.$this->bind.';rport;branch=z9hG4bK'.rand( 100000,999999 );
		$r[] = 'Via: '.$this->via;
		if ( $this->method != 'CANCEL' && $this->routes ) $r[] = 'Route: '.implode(",",array_reverse($this->routes));

		// From
		if ( ! $this->from_tag ) $this->from_tag = rand( 10000,99999 );
		$r[] = 'From: '.$this->from.';tag='.$this->from_tag;

		// To
		if ( $this->to_tag && !in_array($this->method,array("INVITE","CANCEL","NOTIFY","REGISTER")) ) {
			$r[] = 'To: '.$this->to.';tag='.$this->to_tag;
		} else $r[]= 'To: '.$this->to;

		// Authentication
		if ( $this->auth ) {
			$r[] = $this->auth;
			$this->auth = null;
		}

		// Call ID
		$r[] = 'Call-ID: '.$this->callid;

		//CSeq
		if ( $this->method == 'CANCEL' ) $this->cseq--;
		$r[] = 'CSeq: '.$this->cseq.' '.$this->method;

		// Contact
		if ( $this->contact ) {
			if (substr( $this->contact, 0, 1 ) == "<") {
				$r[]= 'Contact: '.$this->contact;
			} else $r[] = 'Contact: <'.$this->contact.'>';
		} elseif ( $this->method != 'MESSAGE' ) {
			$r[] = 'Contact: <sip:'.$this->from_user.'@'.$this->server.':'.$this->bind.'>';
		}

		// Content-Type
		if ( $this->ct ) $r[] = 'Content-Type: '.$this->ct;
		$r[] = 'Max-Forwards: 70';
		$r[] = 'User-Agent: '.$this->ua;
		foreach ($this->headers as $h ) $r[] = $h;

		// Body
		$r[] = 'Content-Length: '.strlen( $this->body );
		if ( $this->body ) $r = implode( "\r\n", $r ) . "\r\n\r\n" . $this->body;

		return $this->sendit( $r );

	}

	// Proxy authentication type
	private function auth() {

		// Check username and password
		if (!( $this->username && $this->password )) return $this->error( 'No user and password' );

		// Check auth realm
		if (preg_match( '/^Proxy-Authenticate: .* realm="(.*)"/imU', $this->message, $ms )) {
			$realm = $ms[1];
		} else return $this->error( 'No realm in auth' );

		// Check auth nonce
		if (preg_match( '/^Proxy-Authenticate: .* nonce="(.*)"/imU', $this->message, $ms )) {
			$nonce = $ms[1];
		} else return $this->error( 'No nonce in auth' );

		// Make digest
		$ha1 = md5( $this->username.':'.$realm.':'.$this->password );
		$ha2 = md5( $this->method.':'.$this->uri );
		$res = md5( $ha1.':'.$nonce.':'.$ha2 );

		// Set authentication string
		$this->auth = 'Proxy-Authorization: Digest username="'.$this->username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$this->uri.'", response="'.$res.'", algorithm=MD5';
		return true;

	}

	// WWW authentication type
	private function wwwauth() {

		// Check username and password
		if (!( $this->username && $this->password )) return $this->error( 'No user and password' );

		// Check auth qop
		$qop = ( strpos( $this->message, 'qop=' ) !== false ) ? true : false;
		if ( $qop && ( strpos( $this->message,'qop="auth"' ) === false ) ) return $this->error( 'Only qop="auth" digest authentication supported' );

		// Check auth realm
		if (preg_match( '/^WWW-Authenticate: .* realm="(.*)"/imU', $this->message, $ms )) {
			$realm = $ms[1];
		} else return $this->error( 'No realm in auth' );

		// Check auth nonce
		if (preg_match( '/^WWW-Authenticate: .* nonce="(.*)"/imU', $this->message, $ms )) {
			$nonce = $ms[1];
		} else return $this->error( 'No nonce in auth' );

		// Make digest
		$ha1 = md5( $this->username.':'.$realm.':'.$this->password );
		$ha2 = md5( strtoupper( $this->method ).':'.$this->uri );
		if ( $qop ) {
		  $cnonce = md5(time());
		  $res = md5( $ha1.':'.$nonce.':00000001:'.$cnonce.':auth:'.$ha2 );
		} else $res = md5( $ha1.':'.$nonce.':'.$ha2 );

		// Set authentication string
		$this->auth = 'Authorization: Digest username="'.$this->username.'", realm="'.$realm.'", nonce="'.$nonce.'", uri="'.$this->uri.'", response="'.$res.'", algorithm=MD5';
		if ( $qop ) $this->auth.= ', qop="auth", nc="00000001", cnonce="'.$cnonce.'"';
		return true;

	}

	// Technical error
	private function error( $text = false ) {
		$this->active = false;
		$this->mode = false;
		if ( $this->debug && $text ) $this->log( $text, 2 );
		return false;
	}

	// Log the request process
	private function log( $data, $type = 0 ) {

		// Choose type
		switch ( $type ) {
			case 0:	$tt = '-> SEND'; break;
			case 1: $tt = '<- RECV'; break;
			case 3: $tt = '@ INFO'; break;
			default: $tt = '! ERROR';
		}

		// Prepare text
		$dd = date( 'Y-m-d H:i:s' );
		$data = str_replace( "\r", '', $data );
		$text = "$tt $dd\n$data\n\n";

		// Check debug type
		if ( $this->debug === true ) {
			echo $text;
			flush();
		} else file_put_contents( $this->debug, $text, FILE_APPEND );

	}

}