<?php

namespace InvincibleBrands\WcMfpc;


if (! defined('ABSPATH')) { exit; }

/**
 * Class Memcached
 *
 * @package InvincibleBrands\WcMfpc
 */
class Memcached extends MemcachedAbstract
{

	protected function _init () {
		/* Memcached class does not exist, Memcached extension is not available */
		if (!class_exists('Memcached')) {
			$this->log (  ' Memcached extension missing, wp-ffpc will not be able to function correctly!', LOG_WARNING );
			return false;
		}

		/* check for existing server list, otherwise we cannot add backends */
		if ( empty ( $this->options['servers'] ) && ! $this->alive ) {
			$this->log (  "Memcached servers list is empty, init failed", LOG_WARNING );
			return false;
		}

		/* check is there's no backend connection yet */
		if ( $this->connection === NULL ) {
			$this->connection = new Memcached();

			/* use binary and not compressed format, good for nginx and still fast */
			$this->connection->setOption( Memcached::OPT_COMPRESSION , false );
                        if ($this->options['memcached_binary']){
                                $this->connection->setOption( Memcached::OPT_BINARY_PROTOCOL , true );
                        }

			if ( version_compare( phpversion( 'memcached' ) , '2.0.0', '>=' ) && ini_get( 'memcached.use_sasl' ) == 1 && isset($this->options['authpass']) && !empty($this->options['authpass']) && isset($this->options['authuser']) && !empty($this->options['authuser']) ) {
				$this->connection->setSaslAuthData ( $this->options['authuser'], $this->options['authpass']);
			}
		}

		/* check if initialization was success or not */
		if ( $this->connection === NULL ) {
			$this->log (   'error initializing Memcached PHP extension, exiting' );
			return false;
		}

		/* check if we already have list of servers, only add server(s) if it's not already connected */
		$servers_alive = array();
		if ( !empty ( $this->status ) ) {
			$servers_alive = $this->connection->getServerList();
			/* create check array if backend servers are already connected */
			if ( !empty ( $servers ) ) {
				foreach ( $servers_alive as $skey => $server ) {
					$skey =  $server['host'] . ":" . $server['port'];
					$servers_alive[ $skey ] = true;
				}
			}
		}

		/* adding servers */
		foreach ( $this->options['servers'] as $server_id => $server ) {
			/* reset server status to unknown */
			//$this->status[$server_id] = -1;

			/* only add servers that does not exists already  in connection pool */
			if ( !@array_key_exists($server_id , $servers_alive ) ) {
				$this->connection->addServer( $server['host'], $server['port'] );
				$this->log ( sprintf(  '%s added',  $server_id ) );
			}
		}

		/* backend is now alive */
		$this->alive = true;
		$this->_status();
	}

	/**
	 * sets current backend alive status for Memcached servers
	 *
	 */
	protected function _status () {
		/* server status will be calculated by getting server stats */
		$this->log (  "checking server statuses");
		/* get server list from connection */
		$servers =  $this->connection->getServerList();

		foreach ( $servers as $server ) {
			$server_id = $server['host'] . self::port_separator . $server['port'];
			/* reset server status to offline */
			$this->status[$server_id] = 0;
				if ($this->connection->set('wp-ffpc', time())) {
					$this->log ( sprintf(  '%s server is up & running',  $server_id ) );
				$this->status[$server_id] = 1;
			}
		}

	}

	/**
	 * get function for Memcached backend
	 *
	 * @param string $key Key to get values for
	 *
	*/
	protected function _get ( &$key ) {
		return $this->connection->get($key);
	}

	/**
	 * Set function for Memcached backend
	 *
	 * @param string $key Key to set with
	 * @param mixed $data Data to set
	 *
	 */
	protected function _set ( &$key, &$data, &$expire ) {
		$result = $this->connection->set ( $key, $data , $expire  );

		/* if storing failed, log the error code */
		if ( $result === false ) {
			$code = $this->connection->getResultCode();
			$this->log ( sprintf(  'unable to set entry: %s',  $key ) );
			$this->log ( sprintf(  'Memcached error code: %s',  $code ) );
			//throw new Exception ( 'Unable to store Memcached entry ' . $key .  ', error code: ' . $code );
		}

		return $result;
	}

	/**
	 *
	 * Flush memcached entries
	 */
	protected function _flush ( ) {
		return $this->connection->flush();
	}


	/**
	 * Removes entry from Memcached or flushes Memcached storage
	 *
	 * @param mixed $keys String / array of string of keys to delete entries with
	*/
	protected function _clear ( &$keys ) {

		/* make an array if only one string is present, easier processing */
		if ( !is_array ( $keys ) )
			$keys = array ( $keys => true );

		foreach ( $keys as $key => $dummy ) {
			$kresult = $this->connection->delete( $key );

			if ( $kresult === false ) {
				$code = $this->connection->getResultCode();
				$this->log ( sprintf(  'unable to delete entry: %s',  $key ) );
				$this->log ( sprintf(  'Memcached error code: %s',  $code ) );
			}
			else {
				$this->log ( sprintf(  'entry deleted: %s',  $key ) );
			}
		}
	}

}
