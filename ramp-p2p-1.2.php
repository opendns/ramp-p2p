<?php
/*
Plugin Name: RAMP Posts to Posts Data Sync
Description: Allows RAMP to sync data from the plugin Posts to Posts
Author: OpenDNS, Crowd Favorite
Version: 1.2
Author URI: http://www.opendns.com, http://www.crowdfavorite.com
*/

/**
 * Action to register the callbacks; must be called at cfd_admin_init
 **/
function p2p_register_deploy_callbacks() {
	global $p2p_deploy_callbacks;
	$p2p_deploy_callbacks = new p2p_deploy_callbacks();
	$p2p_deploy_callbacks->register_deploy_callbacks();
}

add_action('cfd_admin_init', 'p2p_register_deploy_callbacks');

class p2p_deploy_callbacks {
	// The name is used to generate the callback ID in RAMP.  Using a
	// translated name may result in problems if the production and staging
	// servers have different languages set.
	protected $name = 'RAMP Posts to Posts Data Sync';
	protected $description = '';

	public function __construct() {
		// This is a good place to do initial setup, if any is needed
		$this->description = __('Allows RAMP to sync data from the plugin Posts to Posts', 'p2p_ramp');
	}

	/**
	 * Callbacks should be registered in a batch when possible.  Check the name
	 * of the callback a function is being registered to - there is no error
	 * message for using an invalid callback, and it can produce unexpected
	 * results.
	 **/
	public function register_deploy_callbacks() {
		cfd_register_deploy_callback($this->name, $this->description,
			array(
				'send_callback' => array($this, 'send_callback'),
				'receive_callback' => array($this, 'receive_callback'),
				'preflight_send_callback' => array($this, 'preflight_send_callback'),
				'preflight_check_callback' => array($this, 'preflight_check_callback'),
				'preflight_display_callback' => array($this, 'preflight_display_callback'),
				'comparison_send_callback' => array($this, 'comparison_send_callback'),
				'comparison_check_callback' => array($this, 'comparison_check_callback'),
				'comparison_selection_row_callback' => array($this, 'comparison_selection_row_callback'),
			)
		);
	}

	// -- Comparison (New Batch) callbacks

	/**
	 * Runs on the staging server
	 * Generates data to do the "new batch" comparison
	 * Data is passed to comparison_check
	 *
	 * @param array $batch_comparison_data The complete array of batch
	 * comparison data to send.
	 *
	 * @return array Returns an array of arrays of data to be passed to the
	 * comparison_check callback, with the top-level keys being internal
	 * identifiers for 'rows' of this extra's data
	 *
	 **/
	public function comparison_send_callback($batch_comparison_data) {
		// We use the 'send' callback for comparison_send as well, passing an
		// optional second parameter to force the send callback to operate;
		// the send callback can check if it is part of the batch and skip its
		// operation if not, but we want it to always run during comparison.
		$data = $this->send_callback($batch_comparison_data, true);
		return $data;
	}

	/**
 	 * Runs on the production server
	 * Generates data to do the "new batch" comparison
	 * Data is received from comparison_send
	 * Data is passed to comparison_selection_row
	 *
	 * @param array $data The data produced by the comparison_send callback
	 * @param array $batch_items The complete array of batch comparison data
	 * sent
	 *
	 * @return array An array of arrays of data to be passed to the
	 * comparison_selection_row callback, with the top-level keys being
	 * internal identifiers for 'rows' of this extra's data
	 **/
	public function comparison_check_callback($data, $batch_items) {
		$prod_rows = $this->send_callback($data, true);
		return $prod_rows;
	}

	/**
	 * Runs on staging
	 * Generates the "new batch" comparison rows
	 * Data is received from comparison_send and comparison_check
	 * Data is passed to RAMP for display
	 *
	 * Takes the comparison data returned from staging and from production,
	 * and determines if any "New Batch" rows should be displayed (and if
	 * they should have checkboxes, and if so, if those checkboxes should
	 * be checked by default).
	 *
	 * @param array $compiled_data One row of the compiled array of comparison
	 * data, including the following keys:
	 *		status: one row of the data returned by comparison_send
	 *		remote_status: one row of the data returned by comparison_check
	 *		id: the ID of the extra which produced this data
	 *		extra_id: the top-level key of the comparison_* row currently being
	 *		processed
	 *		in_batch: if the corresponding row's checkbox was checked on the
	 *		new batch page
	 *
	 *	@return array An array with three keys:
	 *		selected: true|false|null - true or false checks or unchecks the
	 *		new batch row checkbox by default; null suppresses the checkbox
	 *		forced: true|false (optional) - if true, the checkbox will be
	 *		disabled
	 *		title: The title for the row - usually the plugin name
	 *		message: The message for the row (describing changes if any, et
	 *		cetera)
	 **/
	public function comparison_selection_row_callback($compiled_data) {
		if(isset($compiled_data['remote_status']) && isset($compiled_data['status'])) {
			ksort($compiled_data['remote_status']['meta']);
			ksort($compiled_data['status']['meta']);
			if (serialize($compiled_data['remote_status']['meta']) === serialize($compiled_data['status']['meta'])) {
				return null;
			}

			$ret = array(
				'selected' => $compiled_data['in_batch'],
				'title' => "Update metadata: <strong>{$compiled_data['status']['from_name']}</strong> "
							. "-> <strong>{$compiled_data['status']['to_name']}</strong>",
				'message' => "{$compiled_data['status']['p2p_type']} Connection",
			);

		}
		else if (isset($compiled_data['status'])) {
			$ret = array(
				'selected' => $compiled_data['in_batch'],
				'title' => "<strong>{$compiled_data['status']['from_name']}</strong> "
							. "-> <strong>{$compiled_data['status']['to_name']}</strong>",
				'message' => "{$compiled_data['status']['p2p_type']} Connection",
			);
		}
		else {
			$ret = array(
				'selected' => $compiled_data['in_batch'],
				'title' => "<i>Remove</i> " . "<strong>{$compiled_data['remote_status']['from_name']}</strong> "
							. "-> <strong>{$compiled_data['remote_status']['to_name']}</strong>",
				'message' => "{$compiled_data['remote_status']['p2p_type']} Connection Deletion",
			);
		}

		return $ret;
	}

	// -- Preflight callbacks

	/**
	 * Runs on staging server
	 * Generates the "preflight" check data
	 * Data is passed to preflight_check
	 *
	 *
	 * Prepare staging data to send for the preflight checks
	 *
	 * @param array $batch_data
	 * @return array
	 **/
	public function preflight_send_callback($batch_data) {
		// Again, use the send callback, but this time let it decide if it
		// should run based on whether the row was selected in the batch.
		return $this->send_callback($batch_data);
	}

	/**
	 * Runs on production server
	 * Generates the "preflight" check data
	 * Data is checked by RAMP for flagged error condition
	 *
	 * Receive the staging preflight checked data, compare it to the
	 * production state, and return messages about the preflight; among
	 * other things, trigger an error if there is a change in EPT data
	 * and there is anything in the batch aside from EPT data.
	 *
	 * @param array $data The data produced by the preflight_send callback
	 * @param array $batch_items The complete array of batch preflight data
	 * sent
	 *
	 * @return array A row of preflight messages, with optional subrows
	 * Rows are an array of (all optional) '__message__', '__notice__',
	 * '__warning__', '__error__', which are in turn arrays of strings.  The
	 * presence of any of these but '__message__' will cause the preflight to
	 * block with an error. Optionally, it can also contain a key 'rows', which
	 * is an array of 'Descriptive Name' => array(), where the sub-arrays are
	 * likewise '__message__' et al, which will be shown as sub-rows on the
	 * preflight screen.
	 **/
	public function preflight_check_callback($data, $batch_data) {
		$ret = array('rows' => array());
		$errors = array();
		$delete = 'P2P Link Deletion';
		$sync = 'P2P Link Update';
		$messages = array(__('Selected connections will be synced', 'p2p_ramp'));
		$local_connections = $this->send_callback($batch_data);
		foreach($data as $key => $connection) {
			if (!is_array($connection)) {

				if (!isset($local_connections[$connection])) {
					$errors[] = sprintf(__('Could not find the connection (key %s)'), $connection);
					continue;
				}
				if (!isset($ret['rows'][$delete])) {
					$ret['rows'][$delete]  = array('__message__' => array());
				}
				$local_connection = $local_connections[$connection];
				$ret['rows'][$delete]['__message__'][] = sprintf(__('%s connection will be <i>deleted</i>: <strong>%s</strong> to <strong>%s</strong>', 'p2p_ramp'), $local_connection['p2p_type'], $local_connection['from_name'], $local_connection['to_name']);
				continue;
			}
			$from_valid = $this->is_guid_in_batch_data($batch_data, $connection['from_guid']);
			if(!$from_valid) {
				$from_valid = $this->get_post_by_guid($connection['from_guid']) ? true : false;
			}

			if(!$from_valid) {
				$errors[] = sprintf(__('A selected "%s" connection requires that you send %s \'%s\'', 'p2p_ramp'), $connection['p2p_type'], $connection['from_type'], $connection['from_name']);
			}

			$to_valid = $this->is_guid_in_batch_data($batch_data, $connection['to_guid']);
			if(!$to_valid) {
				$to_valid = $this->get_post_by_guid($connection['to_guid']) ? true : false;
			}

			if(!$to_valid) {
				$errors[] = sprintf(__('A selected "%s" connection requires that you send %s \'%s\'', 'p2p_ramp'), $connection['p2p_type'], $connection['to_type'], $connection['to_name']);
			}

			if ($from_valid && $to_valid) {
				if (!isset($ret['rows'][$sync])) {
					$ret['rows'][$sync]  = array('__message__' => array());
				}
				$to_post = $this->get_post_by_guid($connection['to_guid']);
				$from_post = $this->get_post_by_guid($connection['from_guid']);

				$existing_connection = p2p_get_connections($connection['p2p_type'], array(
					'from' => $from_post->ID,
					'to' => $to_post->ID,
				));
				if ($existing_connection) {
					$ret['rows'][$sync]['__message__'][] = sprintf(__('%s connection will be updated: <strong>%s</strong> to <strong>%s</strong>', 'p2p_ramp'), $connection['p2p_type'], $connection['from_name'], $connection['to_name']);
				}
				else {
					$ret['rows'][$sync]['__message__'][] = sprintf(__('%s connection will be created: <strong>%s</strong> to <strong>%s</strong>', 'p2p_ramp'), $connection['p2p_type'], $connection['from_name'], $connection['to_name']);
				}

			}

		}

		if ($errors) {
			$ret['__error__'] = $errors;
		} else {
			$ret['__message__'] = $messages;
		}

		return $ret;
	}

	/**
	 * Runs on staging server
	 * Optionally changes other messages in the preflight display
	 * Data is checked by RAMP for flagged error condition
	 *
	 * @param array $batch_preflight_data An array of messages; top-level keys
	 * are gross types (post_type, etc) including 'extras'); second-level keys
	 * are rows; below that, the format differs per type.  Extras rows are an
	 * array of (all optional) '__message__', '__notice__', '__warning__',
	 * '__error__';
	 *
	 * @return array The modified messages array
	 **/
	public function preflight_display_callback($batch_preflight_data) {
		return $batch_preflight_data;
	}

	// Transfer Callback Methods

	/**
 	 * Runs on staging server
	 * Prepare staging server data for actual deploy
	 * Data is passed to receive
	 *
	 * @param array $batch_data The complete array of batch
	 * data to send.
	 *
	 * @return array Returns data to be passed to the receive callback.
	 * If the data is empty(), the receive callback will not be called.
	 *
	 **/
	public function send_callback($batch_data, $return_all = false) {
		$extra_id = cfd_make_callback_id($this->name);

		$connection_types = P2P_Connection_Type_Factory::get_all_instances();

		$data = array();

		foreach($connection_types as $type) {
			foreach(p2p_get_connections($type->name) as $row) {
				$from = get_post($row->p2p_from);
				$to = get_post($row->p2p_to);

				$meta = p2p_get_meta($row->p2p_id);

				$key = md5($row->p2p_type . '|' . $from->guid . '|' . $to->guid);
				$data[$key] = (array)$row;
				$data[$key]['from_guid'] = $from->guid;
				$data[$key]['to_guid'] = $to->guid;
				$data[$key]['from_name'] = $from->post_title;
				$data[$key]['to_name'] = $to->post_title;
				$data[$key]['from_type'] = $from->post_type;
				$data[$key]['to_type'] = $to->post_type;
				$data[$key]['meta'] = $meta;
			}
		}

		if($return_all) {
			return $data;
		}
		if(!isset($batch_data['extras'][$extra_id])) {
			return null;
		}
		$keys = array();
		foreach ($batch_data['extras'][$extra_id] as $key => $val) {
			if (is_array($val)) {
				$keys[$key] = $key;
			}
			else {
				$keys[$val] = $val;
			}
		}
		$missing = array_diff_key($keys, $data);
		$return = array_intersect_key($data, $keys);
		foreach ($missing as $key => $ignored) {
			$return[] = $key;
		}
		return $return;
		return array_intersect_key($data, $keys);

	}

	/**
	 * Runs on production server
	 * Update the production server with staging server data
	 * Data is passed from send
	 * Data is passed to RAMP to display messages and error conditions
	 *
	 * @param array $data The data returned by the send callback.
	 *
	 * @return array An array with the following required keys:
	 *		'success': a boolean for successful or failed transfer
	 *		'message': a message to display on the batch complete page for this
	 *		plugin's data
	 **/
	public function receive_callback($data) {
		$success = true;
		$any_success = false;

		$extra_id = cfd_make_callback_id($this->name);
		$faux_batch = array(
			'extras' => array(
				$extra_id => $data
			)
		);

		$local_connections = $this->send_callback($faux_batch);
		foreach($data as $key => $connection) {
			$delete = false;
			if (!is_array($connection)) {
				if (isset($local_connections[$connection])) {
					$delete = true;
					$connection = $local_connections[$connection];
				}
				else {
					continue;
				}
			}
			$from = $this->get_post_by_guid($connection['from_guid']);
			$to = $this->get_post_by_guid($connection['to_guid']);

			if(!$from || !$to) {
				$success = false;
				continue;
			}

			$existing_connection = p2p_get_connections($connection['p2p_type'], array(
				'from' => $from->ID,
				'to' => $to->ID,
			));

			if ($delete) {
				if ($existing_connection) {
					p2p_delete_connection($existing_connection[0]->p2p_id);
					$any_success = true;
				}
				continue;
			}

			if($existing_connection) {
				$p2p_id = $existing_connection[0]->p2p_id;
			} else {
				$p2p_id = p2p_create_connection($connection['p2p_type'], array(
					'from' => $from->ID,
					'to' => $to->ID,
				));
			}

			if($p2p_id) {
				$any_success = true;
			}

			// Sync metadata if there is any
			if(!is_array($connection['meta'])) {
				$connection['meta'] = array();
			}

			$existing_meta = p2p_get_meta($p2p_id);

			// Sync new/existing keys
			foreach($connection['meta'] as $key => $value) {
				$value = $value[0];
				if (isset($existing_meta[$key])) {
					p2p_update_meta($p2p_id, $key, $value);
				} else {
					p2p_add_meta($p2p_id, $key, $value, true);
				}
			}

			// Delete existing keys that no longer exist
			foreach($existing_meta as $key => $value) {
				$value = $value[0];
				if (!isset($connection['meta'][$key])) {
					p2p_delete_meta($p2p_id, $key);
				}
			}

		}

		if($success && $any_success) {
			$message = __('Connections were successfully deployed', 'p2p_ramp');
		} elseif($success && !$any_success) {
			$message = __('No Posts to Posts connections to deploy', 'p2p_ramp');
		} elseif(!$success && $any_success) {
			$message = __('Some connctions were deployed, but others could not be because they referred to missing post or page IDs', 'p2p_ramp');
		} elseif(!$success && !$any_success) {
			$message = __('No connections could be deployed, probably because of missing post or page IDs', 'p2p_ramp');
		}

		return array(
			'success' => $success,
			'message' => $message
		);
	}

	protected function get_post_by_guid($guid) {
		global $wpdb;
		return $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE guid = %s", $guid));
	}

	protected function is_guid_in_batch_data($batch_data, $guid) {
		if(!isset($batch_data['post_types'])) {
			return false;
		}

		foreach($batch_data['post_types'] as $post_type => $posts) {
			if(isset($posts[$guid])) {
				return true;
			}
		}

		return false;

	}

}
