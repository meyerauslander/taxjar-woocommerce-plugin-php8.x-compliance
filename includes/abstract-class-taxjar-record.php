<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

abstract class TaxJar_Record {

	const QUEUE_NAME = 'taxjar_record_queue';
	const API_URI = 'https://api.taxjar.com/v2/';

	protected $queue_id;
	protected $record_id;
	protected $status;
	protected $batch_id;
	protected $created_datetime;
	protected $processed_datetime;
	protected $retry_count;

	public $uri;
	public $object;
	public $taxjar_integration;
	public $data;

	public function __construct( $record_id = null, $set_defaults = false ) {
		$this->taxjar_integration =  WC()->integrations->integrations[ 'taxjar-integration' ];

		if ( ! empty ( $record_id ) ) {
			$this->set_record_id( $record_id );
		}

		if ( $set_defaults ) {
			$this->set_defaults();
		}
	}

	public function load_object() {
		$this->data = $this->get_data_from_object();
	}

	public function read() {
		global $wpdb;

		$queue_id = $this->get_queue_id();
		if ( empty( $queue_id ) ) {
			return false;
		}

		$table_name = self::get_queue_table_name();
		$query = "SELECT * FROM {$table_name} WHERE queue_id = {$queue_id}";
		$results = $wpdb->get_results( $query,  ARRAY_A );

		if ( empty( $results ) || ! is_array( $results ) ) {
			return false;
		}

		$record_data = end( $results );
		if ( empty( $record_data ) ) {
			return false;
		}

		$this->set_record_id( $record_data[ 'record_id' ] );
		$this->set_batch_id( $record_data[ 'batch_id' ] );
		$this->set_created_datetime( $record_data[ 'created_datetime' ] );
		$this->set_processed_datetime( $record_data[ 'processed_datetime' ] );
		$this->set_retry_count( $record_data[ 'retry_count' ] );
		$this->set_status( $record_data[ 'status' ] );
	}

	public function create() {
		if ( empty( $this->get_record_id() ) ) {
			return false;
		}

		global $wpdb;
		$insert = array(
			'record_id'        => $this->get_record_id(),
			'record_type'      => $this->get_record_type(),
			'status'           => $this->get_status(),
			'batch_id'         => $this->get_batch_id(),
			'created_datetime' => $this->get_created_datetime()
		);

		$result = $wpdb->insert( self::get_queue_table_name(), $insert );
		$this->set_queue_id( $wpdb->insert_id );
		return $result;
	}

	public function delete() {
		if ( empty( $this->get_queue_id() ) ) {
			return false;
		}

		global $wpdb;
		$table_name = self::get_queue_table_name();
		return $wpdb->delete( $table_name, array( 'queue_id' => $this->get_queue_id() ) );
	}

	public function save() {
		if ( empty( $this->get_queue_id() ) ) {
			return $this->create();
		}

		global $wpdb;
		$table_name = self::get_queue_table_name();

		$data = array(
			'record_id' => $this->get_record_id(),
			'status' => $this->get_status(),
			'record_type' => $this->get_record_type()
		);

		if ( ! empty( $this->get_processed_datetime() ) ) {
			$data[ 'processed_datetime' ] =  $this->get_processed_datetime();
		}

		if ( ! empty( $this->get_batch_id() ) ) {
			$data[ 'batch_id' ] =  $this->get_batch_id();
		}

		if ( ! empty( $this->get_retry_count() ) ) {
			$data[ 'retry_count' ] =  $this->get_retry_count();
		}

		$where = array(
			'queue_id' => $this->get_queue_id()
		);

		$result = $wpdb->update( $table_name, $data, $where );
		return $result;
	}

	public function set_defaults() {
		$this->set_status( 'new' );
		$this->set_batch_id( 0 );
		$this->set_created_datetime( gmdate( 'Y-m-d H:i:s' ) );
	}

	abstract function get_data_from_object();

	public function get_data() {
		if ( empty( $this->data ) ) {
			$this->data = $this->get_data_from_object();
		}

		return $this->data;
	}

	abstract function sync();
	abstract function should_sync();

	public function sync_success() {
		$current_datetime =  gmdate( 'Y-m-d H:i:s' );
		$this->set_processed_datetime( $current_datetime );
		$this->set_status( 'completed' );
		$this->save();
	}

	public function add_object_sync_metadata() {
		$data = $this->get_data();
		$data_hash = hash( 'md5', serialize( $data ) );
		$sync_datetime =  $this->get_processed_datetime();
		$this->object->update_meta_data( '_taxjar_last_sync', $sync_datetime );
		$this->object->update_meta_data( '_taxjar_hash', $data_hash );
		$this->object->save();
	}

	public function get_last_sync_time() {
		return $this->object->get_meta( '_taxjar_last_sync', true );
	}

	public function get_object_hash() {
		return $this->object->get_meta( '_taxjar_hash', true );
	}

	public function hash_match() {
		$object_hash = $this->get_object_hash();
		$record_hash = hash( 'md5', serialize( $this->get_data() ) );
		if ( $object_hash === $record_hash ) {
			return true;
		} else {
			return false;
		}
	}

	public function sync_failure() {
		$retry_count = $this->get_retry_count() + 1;
		$this->set_retry_count( $retry_count );
		if ( $this->get_retry_count() >= 3 ) {
			$this->set_status( 'failed' );
		} else {
			$this->set_batch_id( 0 );
		}

		$this->save();
	}

	abstract function create_in_taxjar();
	abstract function update_in_taxjar();
	abstract function delete_in_taxjar();
	abstract function get_from_taxjar();

	/**
	 * Find record in queue
	 *
	 * @param int $record_id - record id of item to search queue for
	 * @return TaxJar_Record|bool - if successful returns a TaxJar_Record object, otherwise returns false
	 */
	static function find_active_in_queue( $record_id ) {
		global $wpdb;

		$table_name = self::get_queue_table_name();
		$query = "SELECT queue_id FROM {$table_name} WHERE record_id = {$record_id} AND status IN ( 'new', 'awaiting' )";
		$results = $wpdb->get_results( $query,  ARRAY_A );

		if ( empty( $results ) || ! is_array( $results ) ) {
			return false;
		}

		$last_element = end( $results );
		if ( empty( $last_element[ 'queue_id' ] ) ) {
			return false;
		}

		$record = new static();
		$record->set_queue_id( (int)$last_element[ 'queue_id' ] );
		$record->read();

		return $record;
	}

	public static function create_from_record_row( $record_row ) {
		if ( $record_row[ 'record_type' ] == 'order' ) {
			$record = new TaxJar_Order_Record( $record_row[ 'record_id'] );
		} elseif ( $record_row[ 'record_type' ] == 'refund' ) {
			$record = new TaxJar_Refund_Record( $record_row[ 'record_id'] );
		} else {
			// remove record from queue as it's of a type not supported
			$record = new TaxJar_Order_Record( $record_row[ 'record_id' ] );
			$record->set_queue_id( $record_row[ 'queue_id'] );
			$record->delete();
			return false;
		}

		$record->set_queue_id( $record_row[ 'queue_id'] );
		$record->set_retry_count( $record_row[ 'retry_count'] );
		$record->set_status( $record_row[ 'status'] );
		$record->set_created_datetime( $record_row[ 'status'] );
		$record->set_batch_id( $record_row[ 'batch_id' ] );
		$record->set_processed_datetime( $record_row[ 'processed_datetime' ] );
		$record->load_object();

		// handle records deleted after being added to queue
		if ( ! is_object( $record->object ) ) {
			$record->delete();
			return false;
		}

		return $record;
	}

	/**
	 * Get queue table name
	 *
	 * @return string - name of queue table in db
	 */
	protected static function get_queue_table_name() {
		global $wpdb;
		return $wpdb->prefix . self::QUEUE_NAME;
	}

	public function set_queue_id( $queue_id ) {
		$this->queue_id = $queue_id;
	}

	public function get_queue_id() {
		return $this->queue_id;
	}

	public function set_record_id( $record_id ) {
		$this->record_id = $record_id;
	}

	public function get_record_id() {
		return $this->record_id;
	}

	abstract function get_record_type();

	public function set_status( $status ) {
		$this->status = $status;
	}

	public function get_status() {
		return $this->status;
	}

	public function set_batch_id( $batch_id ) {
		$this->batch_id = $batch_id;
	}

	public function get_batch_id() {
		return $this->batch_id;
	}

	public function set_created_datetime( $datetime ) {
		$this->created_datetime = $datetime;
	}

	public function get_created_datetime() {
		return $this->created_datetime;
	}

	public function set_processed_datetime( $datetime ) {
		$this->processed_datetime = $datetime;
	}

	public function get_processed_datetime() {
		return $this->processed_datetime;
	}

	public function set_retry_count( $retry_count ) {
		$this->retry_count = $retry_count;
	}

	public function get_retry_count() {
		return $this->retry_count;
	}
}